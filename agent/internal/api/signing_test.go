package api

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"testing"
)

// fixtureDir is the shared PHP/Go fixture directory at the repository root.
var fixtureDir = filepath.Join("..", "..", "..", "tests", "Fixtures", "protocol")

type bodyRepeat struct {
	Char  string `json:"char"`
	Count int    `json:"count"`
}

type requestVector struct {
	Name                         string      `json:"name"`
	Secret                       string      `json:"secret"`
	KeyHex                       string      `json:"keyHex"`
	Method                       string      `json:"method"`
	Path                         string      `json:"path"`
	Body                         *string     `json:"body"`
	BodyRepeat                   *bodyRepeat `json:"bodyRepeat"`
	BodySHA256                   string      `json:"bodySha256"`
	Timestamp                    string      `json:"timestamp"`
	Nonce                        string      `json:"nonce"`
	BaseString                   string      `json:"baseString"`
	Signature                    string      `json:"signature"`
	SignatureWithSecretTextAsKey string      `json:"signatureWithSecretTextAsKey"`
	Error                        string      `json:"error"`
}

type responseVector struct {
	Name         string              `json:"name"`
	Secret       string              `json:"secret"`
	KeyHex       string              `json:"keyHex"`
	Status       int                 `json:"status"`
	Path         string              `json:"path"`
	Body         string              `json:"body"`
	BodySHA256   string              `json:"bodySha256"`
	RequestNonce string              `json:"requestNonce"`
	Timestamp    string              `json:"timestamp"`
	BaseString   string              `json:"baseString"`
	Signature    string              `json:"signature"`
	Headers      map[string][]string `json:"headers"`
	Verdict      string              `json:"verdict"`
	Error        string              `json:"error"`
}

type headerVector struct {
	Name    string              `json:"name"`
	Headers map[string][]string `json:"headers"`
	Expect  *RequestHeaders     `json:"expect"`
	Error   string              `json:"error"`
}

type hmacFixture struct {
	Constants struct {
		RequestDomain   string            `json:"requestDomain"`
		ResponseDomain  string            `json:"responseDomain"`
		TimestampWindow int               `json:"timestampWindowSeconds"`
		RequestHeaders  map[string]string `json:"requestHeaders"`
		ResponseHeaders map[string]string `json:"responseHeaders"`
	} `json:"constants"`
	Keys struct {
		Valid []struct {
			Name   string `json:"name"`
			Secret string `json:"secret"`
			KeyHex string `json:"keyHex"`
		} `json:"valid"`
		Invalid []struct {
			Name   string `json:"name"`
			Secret string `json:"secret"`
			Error  string `json:"error"`
		} `json:"invalid"`
	} `json:"keys"`
	Requests             []requestVector  `json:"requests"`
	InvalidRequests      []requestVector  `json:"invalidRequests"`
	RequestHeaders       []headerVector   `json:"requestHeaders"`
	Responses            []responseVector `json:"responses"`
	InvalidResponses     []responseVector `json:"invalidResponses"`
	ResponseVerification []responseVector `json:"responseVerification"`
}

type routesFixture struct {
	Prefix                string `json:"prefix"`
	TransportHardCapBytes int    `json:"transportHardCapBytes"`
	Routes                []struct {
		Name           string `json:"name"`
		Method         string `json:"method"`
		Path           string `json:"path"`
		BodyLimitBytes int    `json:"bodyLimitBytes"`
		RequestSchema  string `json:"requestSchema"`
		ResponseSchema string `json:"responseSchema"`
	} `json:"routes"`
	PathCases []struct {
		Path  string  `json:"path"`
		Route *string `json:"route"`
	} `json:"pathCases"`
}

type errorsFixture struct {
	Codes []struct {
		Code       string `json:"code"`
		HTTPStatus int    `json:"httpStatus"`
		Signed     bool   `json:"signed"`
	} `json:"codes"`
	UnknownKey struct {
		HTTPStatus    int    `json:"httpStatus"`
		Body          string `json:"body"`
		Signed        bool   `json:"signed"`
		ClientVerdict string `json:"clientVerdict"`
	} `json:"unknownKey"`
}

func loadFixture(t *testing.T, name string, target any) {
	t.Helper()
	raw, err := os.ReadFile(filepath.Join(fixtureDir, name+".json"))
	if err != nil {
		t.Fatalf("read fixture %s: %v", name, err)
	}
	if err := json.Unmarshal(raw, target); err != nil {
		t.Fatalf("decode fixture %s: %v", name, err)
	}
}

func (v requestVector) body() []byte {
	if v.BodyRepeat != nil {
		return []byte(strings.Repeat(v.BodyRepeat.Char, v.BodyRepeat.Count))
	}
	return []byte(*v.Body)
}

func mustDecodeSecret(t *testing.T, secret string) []byte {
	t.Helper()
	key, err := DecodeSecret(secret)
	if err != nil {
		t.Fatalf("decode secret: %v", err)
	}
	return key
}

func mustHex(t *testing.T, value string) []byte {
	t.Helper()
	decoded, err := hex.DecodeString(value)
	if err != nil {
		t.Fatalf("decode hex: %v", err)
	}
	return decoded
}

func expectReason(t *testing.T, err error, reason string) {
	t.Helper()
	var protocolErr ProtocolError
	if !errors.As(err, &protocolErr) {
		t.Fatalf("expected protocol error %q, got %v", reason, err)
	}
	if string(protocolErr) != reason {
		t.Fatalf("expected reason %q, got %q", reason, protocolErr)
	}
}

func headerOf(values map[string][]string) http.Header {
	header := http.Header{}
	for name, list := range values {
		for _, value := range list {
			header.Add(name, value)
		}
	}
	return header
}

func TestSigningVectors(t *testing.T) {
	var fixture hmacFixture
	loadFixture(t, "hmac-v1", &fixture)

	t.Run("constants", func(t *testing.T) { testConstants(t, fixture) })
	t.Run("keys", func(t *testing.T) { testKeys(t, fixture) })
	t.Run("requests", func(t *testing.T) { testRequests(t, fixture) })
	t.Run("invalid requests", func(t *testing.T) { testInvalidRequests(t, fixture) })
	t.Run("request headers", func(t *testing.T) { testRequestHeaders(t, fixture) })
	t.Run("responses", func(t *testing.T) { testResponses(t, fixture) })
	t.Run("invalid responses", func(t *testing.T) { testInvalidResponses(t, fixture) })
	t.Run("response verification", func(t *testing.T) { testResponseVerification(t, fixture) })
	t.Run("routes", testRoutes)
	t.Run("errors", func(t *testing.T) { testErrors(t, fixture) })
	t.Run("dtos", testDTOs)
}

func testConstants(t *testing.T, fixture hmacFixture) {
	c := fixture.Constants
	checks := map[string][2]string{
		"request domain":     {c.RequestDomain, RequestDomain},
		"response domain":    {c.ResponseDomain, ResponseDomain},
		"key header":         {c.RequestHeaders["key"], HeaderKey},
		"timestamp header":   {c.RequestHeaders["timestamp"], HeaderTimestamp},
		"nonce header":       {c.RequestHeaders["nonce"], HeaderNonce},
		"signature header":   {c.RequestHeaders["signature"], HeaderSignature},
		"response timestamp": {c.ResponseHeaders["timestamp"], HeaderTimestamp},
		"response nonce":     {c.ResponseHeaders["requestNonce"], HeaderRequestNonce},
		"response signature": {c.ResponseHeaders["signature"], HeaderSignature},
	}
	for name, pair := range checks {
		if pair[0] != pair[1] {
			t.Errorf("%s: fixture %q, code %q", name, pair[0], pair[1])
		}
	}
	if c.TimestampWindow != TimestampWindowSeconds {
		t.Errorf("timestamp window: fixture %d, code %d", c.TimestampWindow, TimestampWindowSeconds)
	}
}

func testKeys(t *testing.T, fixture hmacFixture) {
	for _, vector := range fixture.Keys.Valid {
		key := mustDecodeSecret(t, vector.Secret)
		if hex.EncodeToString(key) != vector.KeyHex {
			t.Errorf("%s: decoded %x, want %s", vector.Name, key, vector.KeyHex)
		}
	}
	for _, vector := range fixture.Keys.Invalid {
		t.Run(vector.Name, func(t *testing.T) {
			_, err := DecodeSecret(vector.Secret)
			expectReason(t, err, vector.Error)
		})
	}
}

func testRequests(t *testing.T, fixture hmacFixture) {
	for _, vector := range fixture.Requests {
		t.Run(vector.Name, func(t *testing.T) {
			key := mustDecodeSecret(t, vector.Secret)
			envelope, err := NewEnvelope(vector.Path, vector.body(), vector.Timestamp, vector.Nonce)
			if err != nil {
				t.Fatalf("envelope: %v", err)
			}
			if envelope.BodySHA256 != vector.BodySHA256 {
				t.Fatalf("body hash %s, want %s", envelope.BodySHA256, vector.BodySHA256)
			}
			base, err := RequestBaseString(vector.Method, envelope)
			if err != nil || base != vector.BaseString {
				t.Fatalf("base string %q (%v), want %q", base, err, vector.BaseString)
			}
			signature, err := SignRequest(key, vector.Method, envelope)
			if err != nil || signature != vector.Signature {
				t.Fatalf("signature %s (%v), want %s", signature, err, vector.Signature)
			}
			if !VerifyRequest(key, vector.Method, envelope, vector.Signature) {
				t.Fatal("valid signature rejected")
			}
			if VerifyRequest(key, vector.Method, envelope, vector.SignatureWithSecretTextAsKey) {
				t.Fatal("displayed secret text was accepted as HMAC key")
			}
			assertRequestTamperingFails(t, key, vector)
		})
	}
}

func assertRequestTamperingFails(t *testing.T, key []byte, vector requestVector) {
	t.Helper()
	body := vector.body()
	otherPath := RoutePrefix + "/heartbeat"
	if vector.Path == otherPath {
		otherPath = RoutePrefix + "/jobs/claim"
	}
	timestamp, _ := strconv.ParseInt(vector.Timestamp, 10, 64)
	otherNonce := reverse(vector.Nonce)
	if otherNonce == vector.Nonce {
		otherNonce += "x"
	}
	tampered := map[string][4]string{
		"body":      {vector.Path, string(append(bytes.Clone(body), ' ')), vector.Timestamp, vector.Nonce},
		"path":      {otherPath, string(body), vector.Timestamp, vector.Nonce},
		"timestamp": {vector.Path, string(body), strconv.FormatInt(timestamp+1, 10), vector.Nonce},
		"nonce":     {vector.Path, string(body), vector.Timestamp, otherNonce},
	}
	for field, input := range tampered {
		envelope, err := NewEnvelope(input[0], []byte(input[1]), input[2], input[3])
		if err != nil {
			t.Fatalf("%s envelope: %v", field, err)
		}
		if VerifyRequest(key, vector.Method, envelope, vector.Signature) {
			t.Errorf("tampered %s verified", field)
		}
	}
	original, _ := NewEnvelope(vector.Path, body, vector.Timestamp, vector.Nonce)
	flipped := vector.Signature[:63] + map[bool]string{true: "1", false: "0"}[vector.Signature[63] == '0']
	for name, signature := range map[string]string{"flipped": flipped, "uppercase": strings.ToUpper(vector.Signature)} {
		if VerifyRequest(key, vector.Method, original, signature) {
			t.Errorf("%s signature verified", name)
		}
	}
	if VerifyRequest(bytes.Repeat([]byte{0x42}, 32), vector.Method, original, vector.Signature) {
		t.Error("foreign key verified")
	}
}

func reverse(value string) string {
	runes := []rune(value)
	for i, j := 0, len(runes)-1; i < j; i, j = i+1, j-1 {
		runes[i], runes[j] = runes[j], runes[i]
	}
	return string(runes)
}

func testInvalidRequests(t *testing.T, fixture hmacFixture) {
	for _, vector := range fixture.InvalidRequests {
		t.Run(vector.Name, func(t *testing.T) {
			envelope, err := NewEnvelopeFromHash(vector.Path, vector.BodySHA256, vector.Timestamp, vector.Nonce)
			if err == nil {
				_, err = SignRequest(mustHex(t, vector.KeyHex), vector.Method, envelope)
			}
			expectReason(t, err, vector.Error)
		})
	}
}

func testRequestHeaders(t *testing.T, fixture hmacFixture) {
	for _, vector := range fixture.RequestHeaders {
		t.Run(vector.Name, func(t *testing.T) {
			parsed, err := ParseRequestHeaders(headerOf(vector.Headers))
			if vector.Error != "" {
				expectReason(t, err, vector.Error)
				return
			}
			if err != nil {
				t.Fatalf("valid header set rejected: %v", err)
			}
			if parsed != *vector.Expect {
				t.Fatalf("parsed %+v, want %+v", parsed, *vector.Expect)
			}
		})
	}
}

func testResponses(t *testing.T, fixture hmacFixture) {
	for _, vector := range fixture.Responses {
		t.Run(vector.Name, func(t *testing.T) {
			key := mustDecodeSecret(t, vector.Secret)
			envelope, err := NewEnvelope(vector.Path, []byte(vector.Body), vector.Timestamp, vector.RequestNonce)
			if err != nil {
				t.Fatalf("envelope: %v", err)
			}
			if envelope.BodySHA256 != vector.BodySHA256 {
				t.Fatalf("body hash %s, want %s", envelope.BodySHA256, vector.BodySHA256)
			}
			base, err := ResponseBaseString(vector.Status, envelope)
			if err != nil || base != vector.BaseString {
				t.Fatalf("base string %q (%v), want %q", base, err, vector.BaseString)
			}
			signature, err := SignResponse(key, vector.Status, envelope)
			if err != nil || signature != vector.Signature {
				t.Fatalf("signature %s (%v), want %s", signature, err, vector.Signature)
			}
			header := http.Header{}
			header.Set(HeaderTimestamp, vector.Timestamp)
			header.Set(HeaderRequestNonce, vector.RequestNonce)
			header.Set(HeaderSignature, vector.Signature)
			verdict := VerifyResponse(key, vector.RequestNonce, vector.Path, vector.Status, header, []byte(vector.Body))
			if verdict != VerdictAccept {
				t.Fatalf("verdict %s, want accept", verdict)
			}
		})
	}
}

func testInvalidResponses(t *testing.T, fixture hmacFixture) {
	for _, vector := range fixture.InvalidResponses {
		t.Run(vector.Name, func(t *testing.T) {
			envelope, err := NewEnvelopeFromHash(vector.Path, vector.BodySHA256, vector.Timestamp, vector.RequestNonce)
			if err == nil {
				_, err = SignResponse(mustHex(t, vector.KeyHex), vector.Status, envelope)
			}
			expectReason(t, err, vector.Error)
		})
	}
}

func testResponseVerification(t *testing.T, fixture hmacFixture) {
	for _, vector := range fixture.ResponseVerification {
		t.Run(vector.Name, func(t *testing.T) {
			key := mustDecodeSecret(t, vector.Secret)
			verdict := VerifyResponse(key, vector.RequestNonce, vector.Path, vector.Status, headerOf(vector.Headers), []byte(vector.Body))
			if string(verdict) != vector.Verdict {
				t.Fatalf("verdict %s, want %s", verdict, vector.Verdict)
			}
		})
	}
}

func testRoutes(t *testing.T) {
	var fixture routesFixture
	loadFixture(t, "routes-v1", &fixture)
	if fixture.Prefix != RoutePrefix || fixture.TransportHardCapBytes != TransportHardCapBytes {
		t.Fatalf("prefix/hard cap mismatch: %s %d", fixture.Prefix, fixture.TransportHardCapBytes)
	}
	actual := Routes()
	if len(actual) != len(fixture.Routes) {
		t.Fatalf("route count %d, want %d", len(actual), len(fixture.Routes))
	}
	for i, want := range fixture.Routes {
		got := actual[i]
		if got.Name != want.Name || got.Method != want.Method || got.Path != want.Path || got.BodyLimitBytes != want.BodyLimitBytes {
			t.Errorf("route %d: %+v, want %+v", i, got, want)
		}
		if err := CheckBodyLimit(want.Name, want.BodyLimitBytes); err != nil {
			t.Errorf("%s: limit rejected: %v", want.Name, err)
		}
		expectReason(t, CheckBodyLimit(want.Name, want.BodyLimitBytes+1), "body_too_large")
		if want.BodyLimitBytes >= TransportHardCapBytes {
			t.Errorf("%s: limit not below transport hard cap", want.Name)
		}
	}
	for _, pathCase := range fixture.PathCases {
		route, err := MatchRoute(pathCase.Path)
		if pathCase.Route == nil {
			if err == nil {
				t.Errorf("path %q accepted as %s", pathCase.Path, route.Name)
				continue
			}
			expectReason(t, err, "invalid_path")
			continue
		}
		if err != nil || route.Name != *pathCase.Route {
			t.Errorf("path %q: %s (%v), want %s", pathCase.Path, route.Name, err, *pathCase.Route)
		}
	}
}

func testErrors(t *testing.T, hmac hmacFixture) {
	var fixture errorsFixture
	loadFixture(t, "errors-v1", &fixture)
	codes := ErrorCodes()
	if len(codes) != len(fixture.Codes) {
		t.Fatalf("code count %d, want %d", len(codes), len(fixture.Codes))
	}
	for i, want := range fixture.Codes {
		if string(codes[i]) != want.Code || codes[i].HTTPStatus() != want.HTTPStatus || !want.Signed {
			t.Errorf("code %d: %s/%d, want %+v", i, codes[i], codes[i].HTTPStatus(), want)
		}
	}
	unknown := fixture.UnknownKey
	if unknown.HTTPStatus != 401 || unknown.Body != "" || unknown.Signed || unknown.ClientVerdict != string(VerdictTerminalUnauthenticated) {
		t.Fatalf("unknown key contract changed: %+v", unknown)
	}
	for _, vector := range hmac.Responses {
		if vector.Status < 400 || vector.Body == "" {
			continue
		}
		var body struct{ Code ErrorCode }
		if err := json.Unmarshal([]byte(vector.Body), &body); err != nil {
			t.Fatalf("%s: %v", vector.Name, err)
		}
		if body.Code.HTTPStatus() != vector.Status {
			t.Errorf("%s: code %s does not map to status %d", vector.Name, body.Code, vector.Status)
		}
	}
}

func TestNewNonceUsesCanonicalRandomFormat(t *testing.T) {
	pattern := regexp.MustCompile(`^[A-Za-z0-9_-]{43}$`)
	seen := map[string]bool{}
	for i := 0; i < 64; i++ {
		nonce, err := NewNonce()
		if err != nil {
			t.Fatal(err)
		}
		if !pattern.MatchString(nonce) || seen[nonce] {
			t.Fatalf("nonce %q invalid or repeated", nonce)
		}
		seen[nonce] = true
	}
}

func TestStructLiteralEnvelopeIsRevalidated(t *testing.T) {
	envelope := Envelope{Path: RoutePrefix + "/heartbeat\nX", BodySHA256: strings.Repeat("0", 64), Timestamp: "1", Nonce: strings.Repeat("a", 32)}
	_, err := SignRequest(bytes.Repeat([]byte{1}, 32), "POST", envelope)
	expectReason(t, err, "invalid_path")
	envelope.Path = RoutePrefix + "/heartbeat"
	envelope.Nonce = "a\nb"
	_, err = SignResponse(bytes.Repeat([]byte{1}, 32), 200, envelope)
	expectReason(t, err, "invalid_nonce")
}

func TestKeyIsCheckedBeforeMethodLikePHP(t *testing.T) {
	envelope, _ := NewEnvelope(RoutePrefix+"/heartbeat", nil, "1", strings.Repeat("a", 32))
	_, err := SignRequest(make([]byte, 31), "GET", envelope)
	expectReason(t, err, "invalid_key")
	_, err = SignResponse(make([]byte, 31), 99, envelope)
	expectReason(t, err, "invalid_key")
}

func TestEnvelopeHashesRawBodyBytes(t *testing.T) {
	body := []byte{0xff, 0x00, '\n'}
	envelope, err := NewEnvelope(RoutePrefix+"/jobs/claim", body, "1", strings.Repeat("a", 32))
	if err != nil {
		t.Fatal(err)
	}
	sum := sha256.Sum256(body)
	if envelope.BodySHA256 != hex.EncodeToString(sum[:]) {
		t.Fatalf("body hash %s", envelope.BodySHA256)
	}
}
