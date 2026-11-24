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
		logger.Warn("agent API uses plain HTTP: requests and responses are HMAC-protected but not confidential",
			"url", baseURL)
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

// Post sends request as JSON to the exact route path and decodes a verified
// 2xx body strictly into response. Signed error answers become *APIError.
func (c *Client) Post(ctx context.Context, path string, request, response any) error {
	route, err := MatchRoute(path)
	if err != nil {
		return err
	}
	body, err := json.Marshal(request)
	if err != nil {
		return fmt.Errorf("agent api: encode request: %w", err)
	}
	if err := CheckBodyLimit(route.Name, len(body)); err != nil {
		return err
	}
	httpRequest, nonce, err := c.signedRequest(ctx, path, body)
	if err != nil {
		return err
	}
	httpResponse, err := c.http.Do(httpRequest)
	if err != nil {
		if ctx.Err() != nil {
			return ctx.Err()
		}
		return &TransportError{Err: err}
	}
	defer httpResponse.Body.Close()
	raw, err := io.ReadAll(io.LimitReader(httpResponse.Body, TransportHardCapBytes+1))
	if err != nil {
		if ctx.Err() != nil {
			return ctx.Err()
		}
		return &TransportError{Err: err}
	}
	if len(raw) > TransportHardCapBytes {
		return &UntrustedResponseError{Status: httpResponse.StatusCode}
	}
	return c.interpret(path, nonce, httpResponse, raw, response)
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
	err := c.Post(ctx, RoutePrefix+"/heartbeat", request, &response)
	return response, err
}

// Claim asks for at most one job targeted at this agent.
func (c *Client) Claim(ctx context.Context) (ClaimResponse, error) {
	var response ClaimResponse
	err := c.Post(ctx, RoutePrefix+"/jobs/claim", ClaimRequest{MaxJobs: 1}, &response)
	return response, err
}

// validator is implemented by response DTOs with semantic checks beyond
// strict JSON decoding.
type validator interface {
	validate() error
}

// decodeStrict accepts exactly one JSON value without unknown members and
// then applies the DTO's own validation.
func decodeStrict(raw []byte, target any) error {
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

func (e *errorResponse) validate() error {
	if !errorCodePattern.MatchString(string(e.Code)) || len(e.Message) > 1024 || !uuidRegexp.MatchString(e.TraceID) {
		return errors.New("invalid error response")
	}
	return nil
}
