package primitives

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/netip"
	"strconv"
	"time"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/executor"
)

// waitHTTP polls the URL of an endpoint alias until it answers with one of
// the expected statuses. Every connection resolves the policy host anew and
// dials only an address inside the policy ranges; TLS still verifies the
// policy host name. Redirects are never followed, proxies never used and
// bodies are read up to the policy limit only.
type waitHTTP struct {
	rootCAs  *x509.CertPool
	lookupIP func(ctx context.Context, host string) ([]net.IP, error)
}

// addressRejected means no resolved address lies inside the policy ranges.
type addressRejected struct {
	host string
	ips  []net.IP
}

func (e *addressRejected) Error() string {
	return fmt.Sprintf("%s resolves to %v, outside the allowed address ranges", e.host, e.ips)
}

func (w *waitHTTP) Run(ctx context.Context, step executor.StepContext) error {
	entry, alias, err := resolve[endpointEntry](step, "endpointAlias", sectionEndpoints)
	if err != nil {
		return err
	}
	statuses, err := actionStatuses(step.Step["expectedStatuses"], entry)
	if err != nil {
		return failure(codePolicy, "endpoint %s: %v", alias, err)
	}
	timeout := time.Duration(entry.TimeoutSeconds) * time.Second
	local, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	client := w.client(entry)
	interval := time.NewTicker(time.Duration(entry.IntervalSeconds) * time.Second)
	defer interval.Stop()
	var last error
	for {
		if err := step.Gate(); err != nil {
			return fmt.Errorf("no new probe may start: %w", err)
		}
		err := w.probe(local, client, entry, statuses)
		var stepError *executor.StepError
		if err == nil || errors.As(err, &stepError) {
			return prefixed(err, "endpoint "+alias)
		}
		// a probe cut off by the deadline says less than the one before
		if last == nil || local.Err() == nil {
			last = err
		}
		select {
		case <-interval.C:
		case <-local.Done():
			if ctx.Err() != nil {
				return fmt.Errorf("%w: %v", executor.ErrStopped, context.Cause(ctx))
			}
			return failure(codeEndpoint, "endpoint %s was not ready within %s: %v", alias, timeout, last)
		}
	}
}

func actionStatuses(value any, entry endpointEntry) (map[int]bool, error) {
	items, _ := value.([]any)
	statuses := map[int]bool{}
	for _, item := range items {
		number, _ := item.(json.Number)
		status, err := strconv.Atoi(number.String())
		if err != nil || !entry.ExpectedStatuses[status] {
			return nil, fmt.Errorf("expected status %s is not allowed by the local policy", number)
		}
		statuses[status] = true
	}
	return statuses, nil
}

// probe returns nil on success, a *StepError for an expected status that
// violates the policy and any other error for a not (yet) ready endpoint.
func (w *waitHTTP) probe(ctx context.Context, client *http.Client, entry endpointEntry, statuses map[int]bool) error {
	request, err := http.NewRequestWithContext(ctx, http.MethodGet, entry.URL.String(), nil)
	if err != nil {
		return failure(codePolicy, "invalid request: %v", err)
	}
	response, err := client.Do(request)
	var rejected *addressRejected
	if errors.As(err, &rejected) {
		return failure(codePolicy, "%v", rejected)
	}
	if err != nil {
		return err
	}
	defer response.Body.Close()
	body, err := io.ReadAll(io.LimitReader(response.Body, entry.MaximumBodyBytes+1))
	if err != nil {
		return err
	}
	// a starting service may answer anything else; it is never followed
	if !statuses[response.StatusCode] {
		return fmt.Errorf("unexpected status %d", response.StatusCode)
	}
	if int64(len(body)) > entry.MaximumBodyBytes {
		return failure(codeEndpoint, "the response body exceeds %d bytes", entry.MaximumBodyBytes)
	}
	if err := checkLocation(response, entry); err != nil {
		return err
	}
	return checkJSONFields(response.StatusCode, body, entry.JSONFields)
}

// checkLocation allows a Location header only where the policy pins its
// exact same-origin value; redirects are never followed.
func checkLocation(response *http.Response, entry endpointEntry) error {
	locations := response.Header.Values("Location")
	want, pinned := entry.Locations[response.StatusCode]
	switch {
	case pinned && (len(locations) != 1 || locations[0] != want):
		return failure(codeEndpoint, "status %d must redirect exactly to %q, got %q", response.StatusCode, want, locations)
	case !pinned && len(locations) > 0:
		return failure(codeEndpoint, "status %d redirects to %q; redirects are not followed", response.StatusCode, locations)
	}
	return nil
}

func checkJSONFields(status int, body []byte, fields map[string]any) error {
	if len(fields) == 0 || status < 200 || status > 299 {
		return nil
	}
	decoder := json.NewDecoder(bytes.NewReader(body))
	decoder.UseNumber()
	var document map[string]any
	if err := decoder.Decode(&document); err != nil {
		return fmt.Errorf("response is not a JSON object: %w", err)
	}
	for name, want := range fields {
		if !jsonEqual(document[name], want) {
			return fmt.Errorf("response field %q is %v, expected %v", name, document[name], want)
		}
	}
	return nil
}

func jsonEqual(got, want any) bool {
	switch typed := want.(type) {
	case int:
		number, ok := got.(json.Number)
		return ok && number.String() == strconv.Itoa(typed)
	default:
		return got == want
	}
}

func (w *waitHTTP) client(entry endpointEntry) *http.Client {
	dialer := &net.Dialer{Timeout: 10 * time.Second}
	transport := &http.Transport{
		Proxy: nil,
		DialContext: func(ctx context.Context, network, address string) (net.Conn, error) {
			return w.dial(ctx, dialer, entry, network, address)
		},
		TLSClientConfig:        &tls.Config{RootCAs: w.rootCAs, MinVersion: tls.VersionTLS12},
		DisableKeepAlives:      true,
		MaxResponseHeaderBytes: 64 << 10,
		ResponseHeaderTimeout:  30 * time.Second,
	}
	return &http.Client{
		Transport:     transport,
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
		Timeout:       time.Duration(min(entry.TimeoutSeconds, 60)) * time.Second,
	}
}

// dial resolves the policy host and connects only to an allowed address.
func (w *waitHTTP) dial(ctx context.Context, dialer *net.Dialer, entry endpointEntry, network, address string) (net.Conn, error) {
	host, port, err := net.SplitHostPort(address)
	if err != nil || host != entry.URL.Hostname() {
		return nil, fmt.Errorf("unexpected dial target %q", address)
	}
	ips := []net.IP{net.ParseIP(host)}
	if ips[0] == nil {
		if ips, err = w.lookupIP(ctx, host); err != nil {
			return nil, err
		}
	}
	var last error = &addressRejected{host: host, ips: ips}
	for _, ip := range ips {
		addr, ok := netip.AddrFromSlice(ip)
		if !ok || !allowedAddress(addr.Unmap(), entry.AllowedRanges) {
			continue
		}
		conn, err := dialer.DialContext(ctx, network, net.JoinHostPort(addr.Unmap().String(), port))
		if err == nil {
			return conn, nil
		}
		last = err
	}
	return nil, last
}

func allowedAddress(addr netip.Addr, ranges []netip.Prefix) bool {
	for _, prefix := range ranges {
		if prefix.Contains(addr) {
			return true
		}
	}
	return false
}

// prefixed adds context to a step error and keeps its code.
func prefixed(err error, context string) error {
	var stepError *executor.StepError
	if errors.As(err, &stepError) {
		return &executor.StepError{Code: stepError.Code, Message: context + ": " + stepError.Message}
	}
	return err
}
