package api

import (
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"net/http"
	"regexp"
	"strconv"
	"strings"
)

// Signature domains and headers of MUWA-HMAC-V1.
const (
	RequestDomain  = "MUWA-HMAC-V1-REQUEST"
	ResponseDomain = "MUWA-HMAC-V1-RESPONSE"

	HeaderKey          = "X-Mucki-Agent-Key"
	HeaderTimestamp    = "X-Mucki-Agent-Timestamp"
	HeaderNonce        = "X-Mucki-Agent-Nonce"
	HeaderRequestNonce = "X-Mucki-Agent-Request-Nonce"
	HeaderSignature    = "X-Mucki-Agent-Signature"

	// KeyBytes is the exact length of the decoded agent secret.
	KeyBytes = 32
)

// ProtocolError is a stable rejection reason shared with the PHP control plane.
type ProtocolError string

func (e ProtocolError) Error() string { return "agent signature input rejected: " + string(e) }

// Rejection reasons, identical to MuckiSafeAgentPlugin\Exception\SignatureException.
const (
	ErrInvalidKey             ProtocolError = "invalid_key"
	ErrInvalidKeyID           ProtocolError = "invalid_key_id"
	ErrInvalidMethod          ProtocolError = "invalid_method"
	ErrInvalidPath            ProtocolError = "invalid_path"
	ErrInvalidBodyHash        ProtocolError = "invalid_body_hash"
	ErrInvalidTimestamp       ProtocolError = "invalid_timestamp"
	ErrInvalidNonce           ProtocolError = "invalid_nonce"
	ErrInvalidStatus          ProtocolError = "invalid_status"
	ErrInvalidSignatureFormat ProtocolError = "invalid_signature_format"
	ErrMissingHeader          ProtocolError = "missing_header"
	ErrDuplicateHeader        ProtocolError = "duplicate_header"
	ErrBodyTooLarge           ProtocolError = "body_too_large"
)

var (
	secretPattern    = regexp.MustCompile(`^[A-Za-z0-9_-]{43}$`)
	keyIDPattern     = regexp.MustCompile(`^[0-9a-f]{32}$`)
	hexSHA256Pattern = regexp.MustCompile(`^[0-9a-f]{64}$`)
	timestampPattern = regexp.MustCompile(`^(0|[1-9][0-9]{0,18})$`)
	noncePattern     = regexp.MustCompile(`^[A-Za-z0-9_-]{32,64}$`)
)

// DecodeSecret decodes the displayed base64url secret (no padding, canonical
// trailing bits) into the 32-byte HMAC key. The text itself is never a key.
func DecodeSecret(secret string) ([]byte, error) {
	if !secretPattern.MatchString(secret) {
		return nil, ErrInvalidKey
	}
	key, err := base64.RawURLEncoding.Strict().DecodeString(secret)
	if err != nil || len(key) != KeyBytes {
		return nil, ErrInvalidKey
	}
	return key, nil
}

// NewNonce returns a request nonce from crypto/rand (32 bytes, base64url).
func NewNonce() (string, error) {
	raw := make([]byte, 32)
	if _, err := rand.Read(raw); err != nil {
		return "", err
	}
	return base64.RawURLEncoding.EncodeToString(raw), nil
}

// Envelope holds the fields shared by request and response base strings.
// For a response, Nonce is the nonce of the answered request.
type Envelope struct {
	Path       string
	BodySHA256 string
	Timestamp  string
	Nonce      string
}

// NewEnvelope hashes the raw body bytes and validates every field.
func NewEnvelope(path string, body []byte, timestamp, nonce string) (Envelope, error) {
	sum := sha256.Sum256(body)
	return NewEnvelopeFromHash(path, hex.EncodeToString(sum[:]), timestamp, nonce)
}

// NewEnvelopeFromHash validates every field; nothing is trimmed or normalized.
func NewEnvelopeFromHash(path, bodySHA256, timestamp, nonce string) (Envelope, error) {
	if _, err := MatchRoute(path); err != nil {
		return Envelope{}, err
	}
	if !hexSHA256Pattern.MatchString(bodySHA256) {
		return Envelope{}, ErrInvalidBodyHash
	}
	if err := checkTimestamp(timestamp); err != nil {
		return Envelope{}, err
	}
	if !noncePattern.MatchString(nonce) {
		return Envelope{}, ErrInvalidNonce
	}
	return Envelope{Path: path, BodySHA256: bodySHA256, Timestamp: timestamp, Nonce: nonce}, nil
}

// validate re-checks an Envelope that may have been built as a struct literal,
// so no unvalidated field (e.g. one containing LF) reaches a base string.
func (e Envelope) validate() error {
	_, err := NewEnvelopeFromHash(e.Path, e.BodySHA256, e.Timestamp, e.Nonce)
	return err
}

func checkTimestamp(timestamp string) error {
	if !timestampPattern.MatchString(timestamp) {
		return ErrInvalidTimestamp
	}
	if _, err := strconv.ParseInt(timestamp, 10, 64); err != nil {
		return ErrInvalidTimestamp
	}
	return nil
}

// RequestBaseString builds the exact request base string (LF between fields, no trailing LF).
func RequestBaseString(method string, envelope Envelope) (string, error) {
	if err := envelope.validate(); err != nil {
		return "", err
	}
	if method != http.MethodPost {
		return "", ErrInvalidMethod
	}
	return strings.Join([]string{RequestDomain, method, envelope.Path, envelope.BodySHA256, envelope.Timestamp, envelope.Nonce}, "\n"), nil
}

// ResponseBaseString builds the exact response base string; the nonce precedes the timestamp.
func ResponseBaseString(status int, envelope Envelope) (string, error) {
	if err := envelope.validate(); err != nil {
		return "", err
	}
	if status < 100 || status > 599 {
		return "", ErrInvalidStatus
	}
	return strings.Join([]string{ResponseDomain, strconv.Itoa(status), envelope.Path, envelope.BodySHA256, envelope.Nonce, envelope.Timestamp}, "\n"), nil
}

// SignRequest returns the lowercase hex HMAC-SHA256 of the request base string.
func SignRequest(key []byte, method string, envelope Envelope) (string, error) {
	if len(key) != KeyBytes {
		return "", ErrInvalidKey
	}
	base, err := RequestBaseString(method, envelope)
	if err != nil {
		return "", err
	}
	return mac(key, base)
}

// SignResponse returns the lowercase hex HMAC-SHA256 of the response base string.
func SignResponse(key []byte, status int, envelope Envelope) (string, error) {
	if len(key) != KeyBytes {
		return "", ErrInvalidKey
	}
	base, err := ResponseBaseString(status, envelope)
	if err != nil {
		return "", err
	}
	return mac(key, base)
}

func mac(key []byte, base string) (string, error) {
	if len(key) != KeyBytes {
		return "", ErrInvalidKey
	}
	h := hmac.New(sha256.New, key)
	h.Write([]byte(base))
	return hex.EncodeToString(h.Sum(nil)), nil
}

// VerifyRequest compares in constant time; a non-canonical signature never verifies.
func VerifyRequest(key []byte, method string, envelope Envelope, signature string) bool {
	if !hexSHA256Pattern.MatchString(signature) {
		return false
	}
	expected, err := SignRequest(key, method, envelope)
	return err == nil && hmac.Equal([]byte(expected), []byte(signature))
}

// RequestHeaders are the four security headers of a request.
type RequestHeaders struct {
	KeyID     string `json:"keyId"`
	Timestamp string `json:"timestamp"`
	Nonce     string `json:"nonce"`
	Signature string `json:"signature"`
}

// ParseRequestHeaders requires each security header exactly once and canonical.
func ParseRequestHeaders(header http.Header) (RequestHeaders, error) {
	var parsed RequestHeaders
	targets := []struct {
		name   string
		target *string
	}{
		{HeaderKey, &parsed.KeyID},
		{HeaderTimestamp, &parsed.Timestamp},
		{HeaderNonce, &parsed.Nonce},
		{HeaderSignature, &parsed.Signature},
	}
	for _, entry := range targets {
		value, err := singleHeader(header, entry.name)
		if err != nil {
			return RequestHeaders{}, err
		}
		*entry.target = value
	}
	switch {
	case !keyIDPattern.MatchString(parsed.KeyID):
		return RequestHeaders{}, ErrInvalidKeyID
	case checkTimestamp(parsed.Timestamp) != nil:
		return RequestHeaders{}, ErrInvalidTimestamp
	case !noncePattern.MatchString(parsed.Nonce):
		return RequestHeaders{}, ErrInvalidNonce
	case !hexSHA256Pattern.MatchString(parsed.Signature):
		return RequestHeaders{}, ErrInvalidSignatureFormat
	}
	return parsed, nil
}

func singleHeader(header http.Header, name string) (string, error) {
	values := header.Values(name)
	switch len(values) {
	case 0:
		return "", ErrMissingHeader
	case 1:
		return values[0], nil
	default:
		return "", ErrDuplicateHeader
	}
}

// Verdict is the client-side classification of an agent API response.
type Verdict string

// Verdicts, identical to MuckiSafeAgentPlugin\Service\Auth\ResponseVerdict.
const (
	// VerdictAccept: signature valid and bound to the request nonce; the body may be used.
	VerdictAccept Verdict = "accept"
	// VerdictTerminalUnauthenticated: unsigned empty 401 of an unknown key; terminal, no semantics.
	VerdictTerminalUnauthenticated Verdict = "terminal_unauthenticated"
	// VerdictReject: never trusted, never used as data source.
	VerdictReject Verdict = "reject"
)

// VerifyResponse classifies a response to the request with requestNonce on path.
// Over HTTP and HTTPS alike, the only unsigned response not rejected is the
// empty 401 of the unknown-key case.
func VerifyResponse(key []byte, requestNonce, path string, status int, header http.Header, body []byte) Verdict {
	if !hasAnySignatureHeader(header) {
		if status == http.StatusUnauthorized && len(body) == 0 {
			return VerdictTerminalUnauthenticated
		}
		return VerdictReject
	}
	timestamp, errTimestamp := singleHeader(header, HeaderTimestamp)
	nonce, errNonce := singleHeader(header, HeaderRequestNonce)
	signature, errSignature := singleHeader(header, HeaderSignature)
	if errTimestamp != nil || errNonce != nil || errSignature != nil || !hexSHA256Pattern.MatchString(signature) {
		return VerdictReject
	}
	if !hmac.Equal([]byte(nonce), []byte(requestNonce)) {
		return VerdictReject
	}
	envelope, err := NewEnvelope(path, body, timestamp, nonce)
	if err != nil {
		return VerdictReject
	}
	expected, err := SignResponse(key, status, envelope)
	if err != nil || !hmac.Equal([]byte(expected), []byte(signature)) {
		return VerdictReject
	}
	return VerdictAccept
}

func hasAnySignatureHeader(header http.Header) bool {
	for _, name := range []string{HeaderTimestamp, HeaderRequestNonce, HeaderSignature} {
		if len(header.Values(name)) > 0 {
			return true
		}
	}
	return false
}
