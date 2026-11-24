package api

import (
	"context"
	"errors"
	"fmt"
)

// ErrUnauthenticated is the unsigned empty 401 of an unknown or unusable key
// id. It is terminal and carries no semantics.
var ErrUnauthenticated = errors.New("agent api: key id not accepted (unsigned 401)")

// TransportError wraps network, TLS and timeout failures. Retryable.
type TransportError struct {
	Err error
}

func (e *TransportError) Error() string { return "agent api transport: " + e.Err.Error() }
func (e *TransportError) Unwrap() error { return e.Err }

// UntrustedResponseError is an unsigned, wrongly signed, redirecting or
// oversized answer. Its body is discarded unread. Retryable, because proxies
// and load balancers produce such answers transiently.
type UntrustedResponseError struct {
	Status int
}

func (e *UntrustedResponseError) Error() string {
	return fmt.Sprintf("agent api: discarded unsigned or invalid response (HTTP %d)", e.Status)
}

// InvalidResponseError is a correctly signed body that violates the V1 DTO
// contract, i.e. a protocol mismatch. Not retryable unless the status is 5xx.
type InvalidResponseError struct {
	Status int
	Err    error
}

func (e *InvalidResponseError) Error() string {
	return fmt.Sprintf("agent api: signed response violates the protocol (HTTP %d): %v", e.Status, e.Err)
}
func (e *InvalidResponseError) Unwrap() error { return e.Err }

// APIError is a signed error DTO of the control plane.
type APIError struct {
	Status  int
	Code    ErrorCode
	Message string
	TraceID string
}

func (e *APIError) Error() string {
	return fmt.Sprintf("agent api: HTTP %d %s: %s (trace %s)", e.Status, e.Code, e.Message, e.TraceID)
}

// Retryable reports whether repeating the call with a fresh nonce and
// timestamp may succeed. Caller cancellation is never retryable.
func Retryable(err error) bool {
	if err == nil {
		return false
	}
	var transport *TransportError
	if errors.As(err, &transport) {
		return true
	}
	if errors.Is(err, context.Canceled) || errors.Is(err, context.DeadlineExceeded) {
		return false
	}
	var untrusted *UntrustedResponseError
	if errors.As(err, &untrusted) {
		return true
	}
	var invalid *InvalidResponseError
	if errors.As(err, &invalid) {
		return invalid.Status >= 500
	}
	var apiErr *APIError
	if errors.As(err, &apiErr) {
		return apiErr.Status >= 500 || apiErr.Code == CodeTimestampOutOfWindow || apiErr.Code == CodeReplayDetected
	}
	return false
}
