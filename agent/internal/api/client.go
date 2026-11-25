package api

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// DefaultTimeout bounds one request including reading the response.
const DefaultTimeout = 30 * time.Second

// ClientOptions configures a Client. RootCAs replaces the system roots (tests,
// private CAs); certificate verification itself can never be disabled.
type ClientOptions struct {
	BaseURL           string
	AllowInsecureHTTP bool
	KeyID             string
	Key               []byte
	Timeout           time.Duration
	RootCAs           *x509.CertPool
	Logger            *slog.Logger
	Now               func() time.Time
}

// Client sends MUWA-HMAC-V1 signed requests and trusts a response only after
// verifying its signature against the request nonce, over HTTP and HTTPS
// alike. It performs exactly one attempt per call; see Backoff for retries.
type Client struct {
	baseURL string
	keyID   string
	key     []byte
	http    *http.Client
	now     func() time.Time
}

// NewClient validates the options. A plain-HTTP base URL needs
// AllowInsecureHTTP and is announced with a warning on every start.
func NewClient(opts ClientOptions) (*Client, error) {
	if err := ValidateServerURL(opts.BaseURL, opts.AllowInsecureHTTP); err != nil {
		return nil, err
	}
	if !keyIDPattern.MatchString(opts.KeyID) {
		return nil, ErrInvalidKeyID
	}
	if len(opts.Key) != KeyBytes {
		return nil, ErrInvalidKey
	}
	timeout := opts.Timeout
	if timeout <= 0 {
		timeout = DefaultTimeout
	}
	logger := opts.Logger
	if logger == nil {
		logger = slog.Default()
	}
	now := opts.Now
	if now == nil {
		now = time.Now
	}
	baseURL := strings.TrimSuffix(opts.BaseURL, "/")
	if strings.HasPrefix(baseURL, "http://") {
		logger.Warn("agent API uses plain HTTP: requests and responses are HMAC-protected but not confidential; "+
			"actions, parameters, results and error output can be read on the network", "url", baseURL)
	}
	transport := &http.Transport{
		Proxy:                 http.ProxyFromEnvironment,
		DialContext:           (&net.Dialer{Timeout: 10 * time.Second, KeepAlive: 30 * time.Second}).DialContext,
		TLSClientConfig:       &tls.Config{MinVersion: tls.VersionTLS12, RootCAs: opts.RootCAs},
		TLSHandshakeTimeout:   10 * time.Second,
		ResponseHeaderTimeout: timeout,
		DisableCompression:    true,
		MaxIdleConns:          2,
		IdleConnTimeout:       90 * time.Second,
		ForceAttemptHTTP2:     true,
	}
	return &Client{
		baseURL: baseURL,
		keyID:   opts.KeyID,
		key:     append([]byte(nil), opts.Key...),
		now:     now,
		http: &http.Client{
			Transport: transport,
			Timeout:   timeout,
			// A redirect is an unsigned answer; it is returned and discarded, never followed.
			CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
		},
	}, nil
}

// ResponseMeta describes a verified response. ServerTime is the signed
// response timestamp, used to calibrate local deadlines against the server.
type ResponseMeta struct {
	ServerTime time.Time
}

// Post sends request as JSON to the exact route path and decodes a verified
// 2xx body strictly into response. Signed error answers become *APIError.
func (c *Client) Post(ctx context.Context, path string, request, response any) (ResponseMeta, error) {
	route, err := MatchRoute(path)
	if err != nil {
		return ResponseMeta{}, err
	}
	body, err := json.Marshal(request)
	if err != nil {
		return ResponseMeta{}, fmt.Errorf("agent api: encode request: %w", err)
	}
	if err := CheckBodyLimit(route.Name, len(body)); err != nil {
		return ResponseMeta{}, err
	}
	httpRequest, nonce, err := c.signedRequest(ctx, path, body)
	if err != nil {
		return ResponseMeta{}, err
	}
	httpResponse, err := c.http.Do(httpRequest)
	if err != nil {
		return ResponseMeta{}, transportError(ctx, err)
	}
	defer httpResponse.Body.Close()
	raw, err := io.ReadAll(io.LimitReader(httpResponse.Body, TransportHardCapBytes+1))
	if err != nil {
		return ResponseMeta{}, transportError(ctx, err)
	}
	if len(raw) > TransportHardCapBytes {
		return ResponseMeta{}, &UntrustedResponseError{Status: httpResponse.StatusCode}
	}
	if err := c.interpret(path, nonce, httpResponse, raw, response); err != nil {
		return ResponseMeta{}, err
	}
	// VerifyResponse already accepted the canonical timestamp header.
	seconds, _ := strconv.ParseInt(httpResponse.Header.Get(HeaderTimestamp), 10, 64)
	return ResponseMeta{ServerTime: time.Unix(seconds, 0).UTC()}, nil
}

func transportError(ctx context.Context, err error) error {
	if ctx.Err() != nil {
		return ctx.Err()
	}
	return &TransportError{Err: err}
}

func (c *Client) signedRequest(ctx context.Context, path string, body []byte) (*http.Request, string, error) {
	nonce, err := NewNonce()
	if err != nil {
		return nil, "", fmt.Errorf("agent api: nonce: %w", err)
	}
	envelope, err := NewEnvelope(path, body, strconv.FormatInt(c.now().Unix(), 10), nonce)
	if err != nil {
		return nil, "", err
	}
	signature, err := SignRequest(c.key, http.MethodPost, envelope)
	if err != nil {
		return nil, "", err
	}
	request, err := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+path, bytes.NewReader(body))
	if err != nil {
		return nil, "", fmt.Errorf("agent api: build request: %w", err)
	}
	request.Header.Set("Content-Type", "application/json")
	request.Header.Set("Accept", "application/json")
	request.Header.Set(HeaderKey, c.keyID)
	request.Header.Set(HeaderTimestamp, envelope.Timestamp)
	request.Header.Set(HeaderNonce, nonce)
	request.Header.Set(HeaderSignature, signature)
	return request, nonce, nil
}

func (c *Client) interpret(path, nonce string, httpResponse *http.Response, raw []byte, response any) error {
	status := httpResponse.StatusCode
	switch VerifyResponse(c.key, nonce, path, status, httpResponse.Header, raw) {
	case VerdictTerminalUnauthenticated:
		return ErrUnauthenticated
	case VerdictAccept:
	default:
		return &UntrustedResponseError{Status: status}
	}
	if status >= 200 && status < 300 {
		if err := decodeStrict(raw, response); err != nil {
			return &InvalidResponseError{Status: status, Err: err}
		}
		return nil
	}
	var dto errorResponse
	if err := decodeStrict(raw, &dto); err != nil {
		return &InvalidResponseError{Status: status, Err: err}
	}
	return &APIError{Status: status, Code: dto.Code, Message: dto.Message, TraceID: dto.TraceID}
}

// HostHeartbeat reports the agent to the control plane.
func (c *Client) HostHeartbeat(ctx context.Context, request HostHeartbeatRequest) (HostHeartbeatResponse, error) {
	var response HostHeartbeatResponse
	_, err := c.Post(ctx, RoutePrefix+"/heartbeat", request, &response)
	return response, err
}

// Claim asks for at most one job targeted at this agent.
func (c *Client) Claim(ctx context.Context) (ClaimResponse, error) {
	var response ClaimResponse
	_, err := c.Post(ctx, RoutePrefix+"/jobs/claim", ClaimRequest{MaxJobs: 1}, &response)
	return response, err
}

// StartJob moves a claimed job to running and returns its materialized deadlines.
func (c *Client) StartJob(ctx context.Context, jobID string, lease JobLeaseRequest) (JobStartResponse, ResponseMeta, error) {
	var response JobStartResponse
	meta, err := c.Post(ctx, jobPath(jobID, "start"), lease, &response)
	return response, meta, err
}

// JobHeartbeat renews the lease of a running job or reports a requested stop.
func (c *Client) JobHeartbeat(ctx context.Context, jobID string, lease JobLeaseRequest) (JobHeartbeatResponse, ResponseMeta, error) {
	var response JobHeartbeatResponse
	meta, err := c.Post(ctx, jobPath(jobID, "heartbeat"), lease, &response)
	return response, meta, err
}

// CompleteJob reports the terminal outcome; an identical retry is answered again.
func (c *Client) CompleteJob(ctx context.Context, jobID string, request JobCompleteRequest) (JobCompleteResponse, error) {
	if err := request.CompletionResult.Validate(); err != nil {
		return JobCompleteResponse{}, err
	}
	var response JobCompleteResponse
	_, err := c.Post(ctx, jobPath(jobID, "complete"), request, &response)
	return response, err
}

// jobPath builds the route path; MatchRoute in Post rejects any non-canonical job id.
func jobPath(jobID, action string) string {
	return RoutePrefix + "/jobs/" + jobID + "/" + action
}

// validator is implemented by response DTOs with semantic checks beyond
// strict JSON decoding.
type validator interface {
	validate() error
}

// requirer lists members that must be present and not null; encoding/json
// silently keeps the zero value for both cases.
type requirer interface {
	required() []string
}

// decodeStrict accepts exactly one JSON value without unknown members and
// then applies the DTO's own validation.
func decodeStrict(raw []byte, target any) error {
	if r, ok := target.(requirer); ok {
		var members map[string]json.RawMessage
		if err := json.Unmarshal(raw, &members); err != nil {
			return err
		}
		for _, name := range r.required() {
			if value, ok := members[name]; !ok || string(value) == "null" {
				return fmt.Errorf("member %s is required", name)
			}
		}
	}
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(target); err != nil {
		return err
	}
	if _, err := decoder.Token(); !errors.Is(err, io.EOF) {
		return errors.New("trailing data after JSON value")
	}
	if v, ok := target.(validator); ok {
		return v.validate()
	}
	return nil
}

var (
	wireTimePattern   = regexp.MustCompile(`^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]Z$`)
	uuidRegexp        = regexp.MustCompile(`^` + uuidPattern + `$`)
	claimTokenPattern = regexp.MustCompile(`^[A-Za-z0-9_-]{43}$`)
	errorCodePattern  = regexp.MustCompile(`^MUWA_AGENT_[A-Z][A-Z_]{0,63}$`)
)

type errorResponse struct {
	Code    ErrorCode `json:"code"`
	Message string    `json:"message"`
	TraceID string    `json:"traceId"`
}

func (e *errorResponse) required() []string { return []string{"code", "message", "traceId"} }

func (e *errorResponse) validate() error {
	if !errorCodePattern.MatchString(string(e.Code)) || len(e.Message) > 1024 || !uuidRegexp.MatchString(e.TraceID) {
		return errors.New("invalid error response")
	}
	return nil
}
