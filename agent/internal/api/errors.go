package api

// ErrorCode is a stable code of the signed error DTO {code, message, traceId}.
type ErrorCode string

// V1 error codes, pinned by tests/Fixtures/protocol/errors-v1.json.
const (
	CodeRequestInvalid       ErrorCode = "MUWA_AGENT_REQUEST_INVALID"
	CodePayloadInvalid       ErrorCode = "MUWA_AGENT_PAYLOAD_INVALID"
	CodeProtocolUnsupported  ErrorCode = "MUWA_AGENT_PROTOCOL_UNSUPPORTED"
	CodeSignatureInvalid     ErrorCode = "MUWA_AGENT_SIGNATURE_INVALID"
	CodeTimestampOutOfWindow ErrorCode = "MUWA_AGENT_TIMESTAMP_OUT_OF_WINDOW"
	CodeReplayDetected       ErrorCode = "MUWA_AGENT_REPLAY_DETECTED"
	CodeRevoked              ErrorCode = "MUWA_AGENT_REVOKED"
	CodeMethodNotAllowed     ErrorCode = "MUWA_AGENT_METHOD_NOT_ALLOWED"
	CodeClaimConflict        ErrorCode = "MUWA_AGENT_CLAIM_CONFLICT"
	CodeBodyTooLarge         ErrorCode = "MUWA_AGENT_BODY_TOO_LARGE"
	CodeUnsupportedMediaType ErrorCode = "MUWA_AGENT_UNSUPPORTED_MEDIA_TYPE"
	CodeInternalError        ErrorCode = "MUWA_AGENT_INTERNAL_ERROR"
	CodeUnavailable          ErrorCode = "MUWA_AGENT_UNAVAILABLE"
)

var errorCodes = []struct {
	code   ErrorCode
	status int
}{
	{CodeRequestInvalid, 400},
	{CodePayloadInvalid, 400},
	{CodeProtocolUnsupported, 400},
	{CodeSignatureInvalid, 401},
	{CodeTimestampOutOfWindow, 401},
	{CodeReplayDetected, 401},
	{CodeRevoked, 401},
	{CodeMethodNotAllowed, 405},
	{CodeClaimConflict, 409},
	{CodeBodyTooLarge, 413},
	{CodeUnsupportedMediaType, 415},
	{CodeInternalError, 500},
	{CodeUnavailable, 503},
}

// ErrorCodes returns all known codes in contract order.
func ErrorCodes() []ErrorCode {
	codes := make([]ErrorCode, 0, len(errorCodes))
	for _, entry := range errorCodes {
		codes = append(codes, entry.code)
	}
	return codes
}

// HTTPStatus returns the HTTP status of a known code and 0 for an unknown one.
func (c ErrorCode) HTTPStatus() int {
	for _, entry := range errorCodes {
		if entry.code == c {
			return entry.status
		}
	}
	return 0
}
