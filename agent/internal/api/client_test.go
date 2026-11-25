package api

import (
	"bytes"
	"context"
	"crypto/x509"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

const (
	testKeyID  = "0123456789abcdef0123456789abcdef"
	testSecret = "AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8"
	testNow    = 1_900_000_000
)

func testKey(t *testing.T) []byte {
	t.Helper()
	key, err := DecodeSecret(testSecret)
	if err != nil {
		t.Fatal(err)
	}
	return key
}

// fakeServer verifies every request like the PHP AgentAuthenticator and
// answers through respond; sign controls whether the answer is signed.
type fakeServer struct {
	t       *testing.T
	key     []byte
	calls   atomic.Int32
	respond func(r *http.Request, body []byte) (status int, response []byte, sign bool)
}

func (f *fakeServer) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	f.calls.Add(1)
	body, _ := io.ReadAll(r.Body)
	headers, err := ParseRequestHeaders(r.Header)
	if err != nil {
		f.t.Errorf("request headers: %v", err)
		w.WriteHeader(http.StatusUnauthorized)
		return
	}
	envelope, err := NewEnvelope(r.URL.Path, body, headers.Timestamp, headers.Nonce)
	if err != nil || r.URL.RawQuery != "" || headers.KeyID != testKeyID || !VerifyRequest(f.key, r.Method, envelope, headers.Signature) {
		f.t.Errorf("request is not correctly signed: %v", err)
		w.WriteHeader(http.StatusUnauthorized)
		return
	}
	if r.Header.Get("Content-Type") != "application/json" || r.Header.Get("Accept") != "application/json" {
		f.t.Errorf("unexpected content negotiation headers %v", r.Header)
	}
	status, response, sign := f.respond(r, body)
	if sign {
		timestamp := strconv.Itoa(testNow)
		responseEnvelope, err := NewEnvelope(r.URL.Path, response, timestamp, headers.Nonce)
		if err != nil {
			f.t.Fatal(err)
		}
		signature, err := SignResponse(f.key, status, responseEnvelope)
		if err != nil {
			f.t.Fatal(err)
		}
		w.Header().Set(HeaderTimestamp, timestamp)
		w.Header().Set(HeaderRequestNonce, headers.Nonce)
		w.Header().Set(HeaderSignature, signature)
	}
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_, _ = w.Write(response)
}

func signedJSON(status int, body string) func(*http.Request, []byte) (int, []byte, bool) {
	return func(*http.Request, []byte) (int, []byte, bool) { return status, []byte(body), true }
}

func newClient(t *testing.T, server *httptest.Server, opts ClientOptions) *Client {
	t.Helper()
	opts.BaseURL = server.URL
	opts.KeyID = testKeyID
	opts.Key = testKey(t)
	opts.Now = func() time.Time { return time.Unix(testNow, 0) }
	if server.TLS == nil {
		opts.AllowInsecureHTTP = true
	} else if opts.RootCAs == nil {
		pool := x509.NewCertPool()
		pool.AddCert(server.Certificate())
		opts.RootCAs = pool
	}
	client, err := NewClient(opts)
	if err != nil {
		t.Fatal(err)
	}
	return client
}

func TestSignedHTTPSRoundTripDecodesTheVerifiedBody(t *testing.T) {
	fake := &fakeServer{t: t, key: testKey(t), respond: signedJSON(200, `{"serverTime":"2030-03-17T17:46:40Z","pollAfterSeconds":10}`)}
	server := httptest.NewTLSServer(fake)
	defer server.Close()

	response, err := newClient(t, server, ClientOptions{}).HostHeartbeat(context.Background(), HostHeartbeatRequest{
		AgentVersion: "1.0.0", Platform: "linux", Arch: "amd64", Capabilities: []string{}, AgentTime: "2030-03-17T17:46:40Z",
	})
	if err != nil {
		t.Fatal(err)
	}
	if response.ServerTime != "2030-03-17T17:46:40Z" || response.PollAfterSeconds != 10 {
		t.Fatalf("unexpected response %+v", response)
	}
}

func TestClaimDecodesAnEmptyAndAFilledQueue(t *testing.T) {
	body := `{"job":null}`
	fake := &fakeServer{t: t, key: testKey(t), respond: func(_ *http.Request, request []byte) (int, []byte, bool) {
		if string(request) != `{"maxJobs":1}` {
			t.Errorf("claim body %s", request)
		}
		return 200, []byte(body), true
	}}
	server := httptest.NewTLSServer(fake)
	defer server.Close()
	client := newClient(t, server, ClientOptions{})

	empty, err := client.Claim(context.Background())
	if err != nil || empty.Job != nil {
		t.Fatalf("expected no job, got %+v / %v", empty, err)
	}

	body = `{"job":{"id":"0190c3a4-5b6e-7f80-9a1b-2c3d4e5f6a7b","claimToken":"AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8",` +
		`"leaseVersion":1,"leaseExpiresAt":"2030-03-17T17:47:10Z","phaseDeadline":"2030-03-17T17:56:40Z",` +
		`"executionDeadline":"2030-03-17T17:56:25Z","stopDeadline":"2030-03-17T17:56:30Z","phase":"pre",` +
		`"idempotencyClass":"safe","action":{"definitionCanonical":"{}","actionHash":"` + strings.Repeat("a", 64) + `"},"parameters":{}}}`
	claimed, err := client.Claim(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if claimed.Job == nil || claimed.Job.ID != "0190c3a4-5b6e-7f80-9a1b-2c3d4e5f6a7b" || claimed.Job.LeaseVersion != 1 || claimed.Job.Phase != "pre" {
		t.Fatalf("unexpected job %+v", claimed.Job)
	}
}

func TestCertificateVerificationStaysActive(t *testing.T) {
	fake := &fakeServer{t: t, key: testKey(t), respond: signedJSON(200, `{"job":null}`)}
	server := httptest.NewTLSServer(fake)
	defer server.Close()

	client := newClient(t, server, ClientOptions{RootCAs: x509.NewCertPool()})
	_, err := client.Claim(context.Background())
	var unknownAuthority x509.UnknownAuthorityError
	if !errors.As(err, &unknownAuthority) {
		t.Fatalf("expected certificate verification failure, got %v", err)
	}
	if fake.calls.Load() != 0 {
		t.Fatal("no request may reach an unverified server")
	}
}

func TestHTTPNeedsTheFlagAndWarnsOnEveryStart(t *testing.T) {
	if _, err := NewClient(ClientOptions{BaseURL: "http://muckisafe.internal", KeyID: testKeyID, Key: make([]byte, 32)}); err == nil {
		t.Fatal("HTTP without AllowInsecureHTTP must be rejected")
	}
	var logs bytes.Buffer
	fake := &fakeServer{t: t, key: testKey(t), respond: signedJSON(200, `{"job":null}`)}
	server := httptest.NewServer(fake)
	defer server.Close()

	client := newClient(t, server, ClientOptions{Logger: slog.New(slog.NewTextHandler(&logs, nil))})
	if !strings.Contains(logs.String(), "level=WARN") || !strings.Contains(logs.String(), "not confidential") {
		t.Fatalf("expected a prominent HTTP warning, got %q", logs.String())
	}
	// The warning names what is readable on the network.
	for _, readable := range []string{"actions", "parameters", "error output"} {
		if !strings.Contains(logs.String(), readable) {
			t.Fatalf("the HTTP warning must say that %s are readable, got %q", readable, logs.String())
		}
	}
	if _, err := client.Claim(context.Background()); err != nil {
		t.Fatalf("signed HTTP must work with the flag: %v", err)
	}
}

func TestInvalidClientOptionsAreRejected(t *testing.T) {
	for _, opts := range []ClientOptions{
		{BaseURL: "https://a.example", KeyID: "agent-linux-01", Key: make([]byte, 32)},
		{BaseURL: "https://a.example", KeyID: testKeyID, Key: make([]byte, 16)},
		{BaseURL: "https://user:pw@a.example", KeyID: testKeyID, Key: make([]byte, 32)},
		{BaseURL: "https://a.example/shop", KeyID: testKeyID, Key: make([]byte, 32)},
	} {
		if _, err := NewClient(opts); err == nil {
			t.Errorf("expected %+v to be rejected", opts.BaseURL)
		}
	}
}

func TestUnsignedEmpty401IsTheOnlyAcceptedUnsignedAnswer(t *testing.T) {
	fake := &fakeServer{t: t, key: testKey(t), respond: func(*http.Request, []byte) (int, []byte, bool) { return 401, nil, false }}
	server := httptest.NewTLSServer(fake)
	defer server.Close()

	_, err := newClient(t, server, ClientOptions{}).Claim(context.Background())
	if !errors.Is(err, ErrUnauthenticated) || Retryable(err) {
		t.Fatalf("expected terminal unauthenticated error, got %v", err)
	}
}

func TestUnsignedOrForgedResponsesAreDiscarded(t *testing.T) {
	cases := map[string]func(*http.Request, []byte) (int, []byte, bool){
		"unsigned 200":       func(*http.Request, []byte) (int, []byte, bool) { return 200, []byte(`{"job":null}`), false },
		"unsigned 401 body":  func(*http.Request, []byte) (int, []byte, bool) { return 401, []byte(`{"code":"x"}`), false },
		"unsigned 502 proxy": func(*http.Request, []byte) (int, []byte, bool) { return 502, []byte("bad gateway"), false },
	}
	for name, respond := range cases {
		t.Run(name, func(t *testing.T) {
			server := httptest.NewTLSServer(&fakeServer{t: t, key: testKey(t), respond: respond})
			defer server.Close()
			_, err := newClient(t, server, ClientOptions{}).Claim(context.Background())
			var untrusted *UntrustedResponseError
			if !errors.As(err, &untrusted) || !Retryable(err) {
				t.Fatalf("expected retryable untrusted response, got %v", err)
			}
		})
	}

	t.Run("signed with another key", func(t *testing.T) {
		other := make([]byte, 32)
		fake := &fakeServer{t: t, key: testKey(t), respond: signedJSON(200, `{"job":null}`)}
		server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			recorder := httptest.NewRecorder()
			fake.ServeHTTP(recorder, r)
			envelope, _ := NewEnvelope(r.URL.Path, recorder.Body.Bytes(), strconv.Itoa(testNow), r.Header.Get(HeaderNonce))
			forged, _ := SignResponse(other, recorder.Code, envelope)
			for name, values := range recorder.Header() {
				w.Header()[name] = values
			}
			w.Header().Set(HeaderSignature, forged)
			w.WriteHeader(recorder.Code)
			_, _ = w.Write(recorder.Body.Bytes())
		}))
		defer server.Close()
		_, err := newClient(t, server, ClientOptions{}).Claim(context.Background())
		var untrusted *UntrustedResponseError
		if !errors.As(err, &untrusted) {
			t.Fatalf("expected forged signature to be rejected, got %v", err)
		}
	})
}

func TestSignedErrorResponsesBecomeTypedErrors(t *testing.T) {
	cases := []struct {
		status    int
		code      ErrorCode
		retryable bool
	}{
		{409, CodeClaimConflict, false},
		{401, CodeRevoked, false},
		{401, CodeSignatureInvalid, false},
		{401, CodeTimestampOutOfWindow, true},
		{401, CodeReplayDetected, true},
		{400, CodePayloadInvalid, false},
		{503, CodeUnavailable, true},
		{500, CodeInternalError, true},
	}
	for _, c := range cases {
		t.Run(string(c.code), func(t *testing.T) {
			body := `{"code":"` + string(c.code) + `","message":"m","traceId":"0190c3a4-5b6e-7f80-9a1b-2c3d4e5f6a7b"}`
			server := httptest.NewTLSServer(&fakeServer{t: t, key: testKey(t), respond: signedJSON(c.status, body)})
			defer server.Close()
			_, err := newClient(t, server, ClientOptions{}).Claim(context.Background())
			var apiErr *APIError
			if !errors.As(err, &apiErr) || apiErr.Status != c.status || apiErr.Code != c.code {
				t.Fatalf("expected API error %d %s, got %v", c.status, c.code, err)
			}
			if Retryable(err) != c.retryable {
				t.Fatalf("retryable = %v, want %v", Retryable(err), c.retryable)
			}
		})
	}
}

func TestSignedButMalformedSuccessBodyIsAProtocolError(t *testing.T) {
	for _, body := range []string{`{"job":null,"extra":1}`, `{"job":null} {}`, `not json`} {
		server := httptest.NewTLSServer(&fakeServer{t: t, key: testKey(t), respond: signedJSON(200, body)})
		_, err := newClient(t, server, ClientOptions{}).Claim(context.Background())
		server.Close()
		var invalid *InvalidResponseError
		if !errors.As(err, &invalid) || Retryable(err) {
			t.Fatalf("expected non-retryable protocol error for %s, got %v", body, err)
		}
	}
}

func TestOversizedResponsesAreNotTrusted(t *testing.T) {
	huge := `{"job":null}` + strings.Repeat(" ", TransportHardCapBytes)
	server := httptest.NewTLSServer(&fakeServer{t: t, key: testKey(t), respond: signedJSON(200, huge)})
	defer server.Close()

	_, err := newClient(t, server, ClientOptions{}).Claim(context.Background())
	var untrusted *UntrustedResponseError
	if !errors.As(err, &untrusted) {
		t.Fatalf("expected oversized response to be discarded, got %v", err)
	}
}

func TestRedirectsAreNeverFollowed(t *testing.T) {
	target := httptest.NewTLSServer(&fakeServer{t: t, key: testKey(t), respond: signedJSON(200, `{"job":null}`)})
	defer target.Close()
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, target.URL+r.URL.Path, http.StatusTemporaryRedirect)
	}))
	defer server.Close()

	_, err := newClient(t, server, ClientOptions{}).Claim(context.Background())
	var untrusted *UntrustedResponseError
	if !errors.As(err, &untrusted) || untrusted.Status != http.StatusTemporaryRedirect {
		t.Fatalf("expected the unsigned redirect to be discarded, got %v", err)
	}
}

func TestRequestTimeoutIsEnforcedAndRetryable(t *testing.T) {
	release := make(chan struct{})
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		<-release
	}))
	defer server.Close()
	defer close(release)

	start := time.Now()
	_, err := newClient(t, server, ClientOptions{Timeout: 200 * time.Millisecond}).Claim(context.Background())
	if err == nil || !Retryable(err) {
		t.Fatalf("expected retryable timeout, got %v", err)
	}
	if elapsed := time.Since(start); elapsed > 2*time.Second {
		t.Fatalf("timeout not enforced, took %s", elapsed)
	}
}

func TestEveryRequestUsesAFreshNonce(t *testing.T) {
	seen := map[string]bool{}
	fake := &fakeServer{t: t, key: testKey(t), respond: func(r *http.Request, _ []byte) (int, []byte, bool) {
		nonce := r.Header.Get(HeaderNonce)
		if seen[nonce] {
			t.Errorf("nonce %s reused", nonce)
		}
		seen[nonce] = true
		return 200, []byte(`{"job":null}`), true
	}}
	server := httptest.NewTLSServer(fake)
	defer server.Close()
	client := newClient(t, server, ClientOptions{})
	for i := 0; i < 5; i++ {
		if _, err := client.Claim(context.Background()); err != nil {
			t.Fatal(err)
		}
	}
}

func TestOversizedRequestsAndForeignPathsAreRejectedLocally(t *testing.T) {
	server := httptest.NewTLSServer(&fakeServer{t: t, key: testKey(t), respond: signedJSON(200, `{}`)})
	defer server.Close()
	client := newClient(t, server, ClientOptions{})

	var out struct{}
	if _, err := client.Post(context.Background(), RoutePrefix+"/jobs/claim", map[string]string{"pad": strings.Repeat("x", 2000)}, &out); !errors.Is(err, ErrBodyTooLarge) {
		t.Fatalf("expected local body limit, got %v", err)
	}
	if _, err := client.Post(context.Background(), RoutePrefix+"/jobs/claim?x=1", struct{}{}, &out); !errors.Is(err, ErrInvalidPath) {
		t.Fatalf("expected path rejection, got %v", err)
	}
}

func TestCredentialsNeverAppearInErrorsOrLogs(t *testing.T) {
	var logs bytes.Buffer
	server := httptest.NewServer(&fakeServer{t: t, key: testKey(t), respond: signedJSON(503, `{"code":"MUWA_AGENT_UNAVAILABLE","message":"m","traceId":"0190c3a4-5b6e-7f80-9a1b-2c3d4e5f6a7b"}`)})
	defer server.Close()
	client := newClient(t, server, ClientOptions{Logger: slog.New(slog.NewTextHandler(&logs, &slog.HandlerOptions{Level: slog.LevelDebug}))})

	_, err := client.Claim(context.Background())
	for _, text := range []string{err.Error(), logs.String()} {
		if strings.Contains(text, testSecret) || strings.Contains(text, "0001020304050607") {
			t.Fatalf("secret material leaked: %s", text)
		}
	}
}

const testJob = "0190c3a4-5b6e-7f80-9a1b-2c3d4e5f6a7b"
const testToken = "Tx3IBqFEOrmbYYXG-3ry1Cq-8GW8waH3hgKndGrL-dw"

func TestJobRoutesAreSignedAgainstTheConcreteJobPath(t *testing.T) {
	var paths []string
	fake := &fakeServer{t: t, key: testKey(t), respond: func(r *http.Request, body []byte) (int, []byte, bool) {
		paths = append(paths, r.URL.Path)
		switch {
		case strings.HasSuffix(r.URL.Path, "/start"):
			if string(body) != `{"claimToken":"`+testToken+`","leaseVersion":3}` {
				t.Errorf("start body %s", body)
			}
			return 200, []byte(`{"leaseVersion":3,"leaseExpiresAt":"2030-03-17T17:47:10Z","phaseDeadline":"2030-03-17T17:56:40Z","executionDeadline":"2030-03-17T17:56:25Z","stopDeadline":"2030-03-17T17:56:30Z"}`), true
		case strings.HasSuffix(r.URL.Path, "/heartbeat"):
			return 200, []byte(`{"leaseVersion":3,"leaseExpiresAt":"2030-03-17T17:47:10Z","stopRequested":true,"stopDeadline":"2030-03-17T17:46:45Z"}`), true
		default:
			if string(body) != `{"claimToken":"`+testToken+`","leaseVersion":3,"outcome":"failed","resultCode":"step_failed","summary":"s","stderrTail":"e","stderrEncoding":"utf-8"}` {
				t.Errorf("complete body %s", body)
			}
			return 200, []byte(`{"accepted":true,"terminalStatus":"failed"}`), true
		}
	}}
	server := httptest.NewTLSServer(fake)
	defer server.Close()
	client := newClient(t, server, ClientOptions{})
	lease := JobLeaseRequest{ClaimToken: testToken, LeaseVersion: 3}

	started, meta, err := client.StartJob(context.Background(), testJob, lease)
	if err != nil || started.ExecutionDeadline != "2030-03-17T17:56:25Z" || !meta.ServerTime.Equal(time.Unix(testNow, 0)) {
		t.Fatalf("start: %+v %+v %v", started, meta, err)
	}
	beat, _, err := client.JobHeartbeat(context.Background(), testJob, lease)
	if err != nil || !beat.StopRequested || beat.StopDeadline != "2030-03-17T17:46:45Z" {
		t.Fatalf("heartbeat: %+v %v", beat, err)
	}
	stderr, encoding := "e", "utf-8"
	done, err := client.CompleteJob(context.Background(), testJob, JobCompleteRequest{JobLeaseRequest: lease, CompletionResult: CompletionResult{
		Outcome: "failed", ResultCode: "step_failed", Summary: "s", StderrTail: &stderr, StderrEncoding: &encoding,
	}})
	if err != nil || !done.Accepted || done.TerminalStatus != "failed" {
		t.Fatalf("complete: %+v %v", done, err)
	}
	want := []string{RoutePrefix + "/jobs/" + testJob + "/start", RoutePrefix + "/jobs/" + testJob + "/heartbeat", RoutePrefix + "/jobs/" + testJob + "/complete"}
	if strings.Join(paths, ",") != strings.Join(want, ",") {
		t.Fatalf("paths %v", paths)
	}
}

func TestJobRoutesRejectForeignJobIDsLocally(t *testing.T) {
	server := httptest.NewTLSServer(&fakeServer{t: t, key: testKey(t), respond: signedJSON(200, `{}`)})
	defer server.Close()
	client := newClient(t, server, ClientOptions{})
	for _, id := range []string{"../claim", strings.ToUpper(testJob), "0190c3a45b6e7f809a1b2c3d4e5f6a7b"} {
		if _, _, err := client.StartJob(context.Background(), id, JobLeaseRequest{ClaimToken: testToken, LeaseVersion: 1}); !errors.Is(err, ErrInvalidPath) {
			t.Errorf("%q: expected path rejection, got %v", id, err)
		}
	}
}

func TestCompletionResultFollowsTheOutcomeMatrix(t *testing.T) {
	tail, utf8, b64 := "x", "utf-8", "base64"
	valid := []CompletionResult{
		{Outcome: "succeeded", ResultCode: "ok", Summary: "done"},
		{Outcome: "ambiguous", ResultCode: "journal_started", Summary: ""},
		{Outcome: "failed", ResultCode: "step_failed", Summary: "x", StdoutTail: &tail, StdoutEncoding: &utf8},
		{Outcome: "timed_out", ResultCode: "deadline", Summary: strings.Repeat("ä", 4096), StderrTail: &tail, StderrEncoding: &b64},
	}
	for _, r := range valid {
		if err := r.Validate(); err != nil {
			t.Errorf("%+v: %v", r.Outcome, err)
		}
	}
	invalid := []CompletionResult{
		{Outcome: "unknown", ResultCode: "ok"},
		{Outcome: "succeeded", ResultCode: "OK"},
		{Outcome: "succeeded", ResultCode: "ok", Summary: strings.Repeat("x", 4097)},
		{Outcome: "succeeded", ResultCode: "ok", Summary: "\xff"},
		{Outcome: "succeeded", ResultCode: "ok", StdoutTail: &tail, StdoutEncoding: &utf8},
		{Outcome: "ambiguous", ResultCode: "x", StderrTail: &tail, StderrEncoding: &utf8},
		{Outcome: "failed", ResultCode: "x", StdoutTail: &tail},
		{Outcome: "failed", ResultCode: "x", StdoutEncoding: &utf8},
	}
	for i, r := range invalid {
		if err := r.Validate(); err == nil {
			t.Errorf("invalid case %d accepted", i)
		}
	}
}
