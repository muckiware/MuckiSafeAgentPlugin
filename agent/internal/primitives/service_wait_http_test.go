package primitives

import (
	"context"
	"crypto/x509"
	"fmt"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
)

func waitStep(alias string, statuses ...int) string {
	text := make([]string, len(statuses))
	for i, status := range statuses {
		text[i] = fmt.Sprint(status)
	}
	return `{"primitive":"service.wait-http","endpointAlias":"` + alias + `","expectedStatuses":[` + strings.Join(text, ",") + `]}`
}

// endpointPolicy binds alias "web" to url with the given extra YAML lines.
func endpointPolicy(url string, extra ...string) string {
	return "version: 1\nendpoints:\n  web:\n    url: " + url + "\n" +
		"    expectedStatuses: [200, 302]\n    allowedIpRanges: [127.0.0.0/8]\n" +
		"    timeoutSeconds: 1\n    intervalSeconds: 1\n" + strings.Join(extra, "")
}

func server(t *testing.T, handler http.HandlerFunc) (*httptest.Server, *atomic.Int32) {
	t.Helper()
	var requests atomic.Int32
	s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requests.Add(1)
		handler(w, r)
	}))
	t.Cleanup(s.Close)
	return s, &requests
}

func waitHarness(t *testing.T, policy string) (*harness, *waitHTTP) {
	h := newHarness(t, policy)
	return h, h.primitives["service.wait-http"].(*waitHTTP)
}

func TestWaitHTTPSucceedsOnAnExpectedStatus(t *testing.T) {
	s, requests := server(t, func(w http.ResponseWriter, _ *http.Request) { fmt.Fprint(w, "ok") })
	h, _ := waitHarness(t, endpointPolicy(s.URL+"/health"))

	expect(t, h.run(context.Background(), waitStep("web", 200)), "succeeded", "ok")
	if requests.Load() != 1 {
		t.Fatalf("%d requests", requests.Load())
	}
}

func TestWaitHTTPPollsUntilTheEndpointIsReady(t *testing.T) {
	var calls atomic.Int32
	s, _ := server(t, func(w http.ResponseWriter, _ *http.Request) {
		if calls.Add(1) == 1 {
			w.WriteHeader(http.StatusServiceUnavailable)
		}
	})
	h, _ := waitHarness(t, strings.Replace(endpointPolicy(s.URL), "timeoutSeconds: 1", "timeoutSeconds: 5", 1))

	expect(t, h.run(context.Background(), waitStep("web", 200)), "succeeded", "ok")
	if calls.Load() != 2 {
		t.Fatalf("%d probes", calls.Load())
	}
}

func TestWaitHTTPToleratesLargeOrRedirectingAnswersWhileStarting(t *testing.T) {
	var calls atomic.Int32
	s, _ := server(t, func(w http.ResponseWriter, r *http.Request) {
		switch calls.Add(1) {
		case 1:
			w.WriteHeader(http.StatusServiceUnavailable)
			fmt.Fprint(w, strings.Repeat("starting ", 100))
		case 2:
			http.Redirect(w, r, "/setup", http.StatusTemporaryRedirect)
		}
	})
	h, _ := waitHarness(t, strings.Replace(endpointPolicy(s.URL, "    maximumBodyBytes: 16\n"), "timeoutSeconds: 1", "timeoutSeconds: 10", 1))

	expect(t, h.run(context.Background(), waitStep("web", 200)), "succeeded", "ok")
	if calls.Load() != 3 {
		t.Fatalf("%d probes", calls.Load())
	}
}

func TestWaitHTTPFailsAfterItsPolicyTimeout(t *testing.T) {
	s, _ := server(t, func(w http.ResponseWriter, _ *http.Request) { w.WriteHeader(http.StatusServiceUnavailable) })
	h, _ := waitHarness(t, endpointPolicy(s.URL))

	started := time.Now()
	expect(t, h.run(context.Background(), waitStep("web", 200)), "failed", codeEndpoint, "not ready", "unexpected status 503")
	if elapsed := time.Since(started); elapsed > 3*time.Second {
		t.Fatalf("took %s", elapsed)
	}
}

func TestWaitHTTPStatusesMustBeASubsetOfThePolicy(t *testing.T) {
	s, requests := server(t, func(http.ResponseWriter, *http.Request) {})
	h, _ := waitHarness(t, endpointPolicy(s.URL))

	expect(t, h.run(context.Background(), waitStep("web", 200, 204)), "failed", codePolicy, "204")
	expect(t, h.run(context.Background(), waitStep("other", 200)), "failed", codePolicy, "other")
	if requests.Load() != 0 {
		t.Fatal("a rejected step must not probe")
	}
}

func TestWaitHTTPNeverFollowsRedirects(t *testing.T) {
	var followed atomic.Int32
	s, _ := server(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/elsewhere" {
			followed.Add(1)
			return
		}
		http.Redirect(w, r, "/elsewhere", http.StatusFound)
	})
	h, _ := waitHarness(t, endpointPolicy(s.URL+"/"))

	expect(t, h.run(context.Background(), waitStep("web", 200, 302)), "failed", codeEndpoint, "redirects are not followed")
	if followed.Load() != 0 {
		t.Fatal("the redirect target was requested")
	}
}

func TestWaitHTTPAcceptsOnlyThePinnedSameOriginLocation(t *testing.T) {
	pinned := "    locations:\n      302: /accounts/login/?next=/\n"
	for location, outcome := range map[string]string{"/accounts/login/?next=/": "succeeded", "/accounts/other/": "failed"} {
		s, _ := server(t, func(w http.ResponseWriter, r *http.Request) { http.Redirect(w, r, location, http.StatusFound) })
		h, _ := waitHarness(t, endpointPolicy(s.URL+"/", pinned))
		result := h.run(context.Background(), waitStep("web", 200, 302))
		if result.Outcome != outcome || (outcome == "failed" && result.ResultCode != codeEndpoint) {
			t.Fatalf("%s: %+v", location, result)
		}
	}
}

func TestWaitHTTPRefusesAddressesOutsideThePolicyRanges(t *testing.T) {
	s, requests := server(t, func(http.ResponseWriter, *http.Request) {})
	h, _ := waitHarness(t, strings.Replace(endpointPolicy(s.URL), "127.0.0.0/8", "10.0.0.0/8", 1))

	expect(t, h.run(context.Background(), waitStep("web", 200)), "failed", codePolicy, "outside the allowed address ranges")
	if requests.Load() != 0 {
		t.Fatal("a refused address was contacted")
	}
}

func TestWaitHTTPResolvesEveryConnectionAndDialsOnlyAllowedAddresses(t *testing.T) {
	s, _ := server(t, func(w http.ResponseWriter, _ *http.Request) { w.WriteHeader(http.StatusServiceUnavailable) })
	port := s.Listener.Addr().(*net.TCPAddr).Port
	h, wait := waitHarness(t, strings.Replace(endpointPolicy(fmt.Sprintf("http://service.test:%d/", port)), "timeoutSeconds: 1", "timeoutSeconds: 3", 1))
	var lookups atomic.Int32
	wait.lookupIP = func(_ context.Context, host string) ([]net.IP, error) {
		if host != "service.test" {
			t.Errorf("resolved %q", host)
		}
		if lookups.Add(1) > 1 {
			return []net.IP{net.ParseIP("192.0.2.10")}, nil // rebinding after the first connection
		}
		return []net.IP{net.ParseIP("192.0.2.10"), net.ParseIP("127.0.0.1")}, nil
	}

	expect(t, h.run(context.Background(), waitStep("web", 200)), "failed", codePolicy, "192.0.2.10")
	if lookups.Load() != 2 {
		t.Fatalf("%d lookups, every connection must resolve again", lookups.Load())
	}
}

func TestWaitHTTPSVerifiesThePolicyHostWhileDialingThePinnedAddress(t *testing.T) {
	s := httptest.NewTLSServer(http.HandlerFunc(func(http.ResponseWriter, *http.Request) {}))
	t.Cleanup(s.Close)
	port := s.Listener.Addr().(*net.TCPAddr).Port
	pool := x509.NewCertPool()
	pool.AddCert(s.Certificate())
	resolveLocal := func(context.Context, string) ([]net.IP, error) { return []net.IP{net.ParseIP("127.0.0.1")}, nil }

	// the test certificate is valid for example.com
	h, wait := waitHarness(t, endpointPolicy(fmt.Sprintf("https://example.com:%d/", port)))
	wait.rootCAs, wait.lookupIP = pool, resolveLocal
	expect(t, h.run(context.Background(), waitStep("web", 200)), "succeeded", "ok")

	h, wait = waitHarness(t, endpointPolicy(fmt.Sprintf("https://service.test:%d/", port)))
	wait.rootCAs, wait.lookupIP = pool, resolveLocal
	expect(t, h.run(context.Background(), waitStep("web", 200)), "failed", codeEndpoint, "certificate")
}

func TestWaitHTTPLimitsTheResponseBody(t *testing.T) {
	s, _ := server(t, func(w http.ResponseWriter, _ *http.Request) { fmt.Fprint(w, strings.Repeat("x", 100)) })
	h, _ := waitHarness(t, endpointPolicy(s.URL, "    maximumBodyBytes: 16\n"))

	expect(t, h.run(context.Background(), waitStep("web", 200)), "failed", codeEndpoint, "exceeds 16 bytes")
}

func TestWaitHTTPComparesPolicyJSONFields(t *testing.T) {
	fields := "    jsonFields:\n      major: 3\n      version: \"2026.10.1\"\n"
	run := func(body string) api.CompletionResult {
		s, _ := server(t, func(w http.ResponseWriter, _ *http.Request) { fmt.Fprint(w, body) })
		h, _ := waitHarness(t, endpointPolicy(s.URL, fields))
		return h.run(context.Background(), waitStep("web", 200))
	}
	expect(t, run(`{"major":3,"minor":3,"patch":5,"version":"2026.10.1"}`), "succeeded", "ok")
	expect(t, run(`{"major":"3","version":"2026.10.1"}`), "failed", codeEndpoint, `field "major"`)
}

func TestWaitHTTPIgnoresProxyEnvironment(t *testing.T) {
	s, requests := server(t, func(http.ResponseWriter, *http.Request) {})
	t.Setenv("HTTP_PROXY", "http://127.0.0.1:1")
	t.Setenv("http_proxy", "http://127.0.0.1:1")
	h, _ := waitHarness(t, endpointPolicy(s.URL))

	expect(t, h.run(context.Background(), waitStep("web", 200)), "succeeded", "ok")
	if requests.Load() != 1 {
		t.Fatal("the request did not reach the endpoint directly")
	}
}

func TestWaitHTTPStopsWithTheExecution(t *testing.T) {
	s, _ := server(t, func(w http.ResponseWriter, _ *http.Request) { w.WriteHeader(http.StatusServiceUnavailable) })
	h, _ := waitHarness(t, strings.Replace(endpointPolicy(s.URL), "timeoutSeconds: 1", "timeoutSeconds: 30", 1))
	ctx, cancel := context.WithTimeout(context.Background(), 200*time.Millisecond)
	defer cancel()

	expect(t, h.run(ctx, waitStep("web", 200)), "failed", "execution_stopped")
}

func TestEndpointPolicyRejectsUnsafeURLs(t *testing.T) {
	for _, target := range []string{"http://user:secret@127.0.0.1/", "http://127.0.0.1/#x", "ftp://127.0.0.1/", "/relative", "http:///x"} {
		if _, err := url.Parse(target); err == nil {
			if _, err := endpointURL(target); err == nil {
				t.Errorf("%q accepted", target)
			}
		}
	}
}
