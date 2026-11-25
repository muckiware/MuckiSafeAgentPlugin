package daemon

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/action"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/executor"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/version"
)

const (
	keyID  = "0123456789abcdef0123456789abcdef"
	secret = "AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8"
	jobID  = "0190c3a4-5b6e-7f80-9a1b-2c3d4e5f6a7b"
	token  = "Tx3IBqFEOrmbYYXG-3ry1Cq-8GW8waH3hgKndGrL-dw"
)

// TestMain turns the test binary into the fake action process when it is
// started as `<binary> muwa-helper sleep`.
func TestMain(m *testing.M) {
	if len(os.Args) > 2 && os.Args[1] == "muwa-helper" && os.Args[2] == "sleep" {
		time.Sleep(time.Hour)
		os.Exit(0)
	}
	os.Exit(m.Run())
}

// host is a temporary agent installation: config, secret, policy, state and
// wakeup directory, all owned by the test user like root owns them on a host.
type host struct {
	dir    string
	config string
	policy string
	state  string
}

func newHost(t *testing.T, serverURL string) host {
	t.Helper()
	dir, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	h := host{dir: dir, config: filepath.Join(dir, "config.yaml"), policy: filepath.Join(dir, "policy.yaml"), state: filepath.Join(dir, "state")}
	write(t, filepath.Join(dir, "secret"), secret+"\n", 0o600)
	write(t, h.policy, "version: 1\n", 0o644)
	for _, sub := range []string{h.state, filepath.Join(dir, "wakeup")} {
		if err := os.Mkdir(sub, 0o700); err != nil {
			t.Fatal(err)
		}
	}
	write(t, h.config, "server:\n  url: "+serverURL+"\n  allowInsecureHttp: true\n  keyId: "+keyID+"\n  secretFile: "+filepath.Join(dir, "secret")+"\n"+
		"agent:\n  pollInterval: 1s\n  heartbeatInterval: 1s\n  leaseDuration: 4s\n"+
		"  wakeupDirectory: "+filepath.Join(dir, "wakeup")+"\n  stateDirectory: "+h.state+"\n  policyFile: "+h.policy+"\n", 0o600)
	return h
}

func write(t *testing.T, path, content string, mode os.FileMode) {
	t.Helper()
	if err := os.WriteFile(path, []byte(content), mode); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(path, mode); err != nil {
		t.Fatal(err)
	}
}

// controlPlane is a signing fake of the PHP agent routes with one queued job.
type controlPlane struct {
	t          *testing.T
	key        []byte
	mu         sync.Mutex
	calls      map[string]int
	heartbeats []api.HostHeartbeatRequest
	queued     bool
	completed  []api.JobCompleteRequest
	journal    []string
	state      string
	unsigned   bool
}

func newControlPlane(t *testing.T, queued bool) (*controlPlane, *httptest.Server) {
	t.Helper()
	key, err := api.DecodeSecret(secret)
	if err != nil {
		t.Fatal(err)
	}
	plane := &controlPlane{t: t, key: key, calls: map[string]int{}, queued: queued}
	server := httptest.NewServer(plane)
	t.Cleanup(server.Close)
	return plane, server
}

func (p *controlPlane) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	body, _ := io.ReadAll(r.Body)
	headers, err := api.ParseRequestHeaders(r.Header)
	envelope, envErr := api.NewEnvelope(r.URL.Path, body, headers.Timestamp, headers.Nonce)
	if err != nil || envErr != nil || headers.KeyID != keyID || !api.VerifyRequest(p.key, r.Method, envelope, headers.Signature) {
		p.t.Errorf("request to %s is not correctly signed", r.URL.Path)
		w.WriteHeader(http.StatusUnauthorized)
		return
	}
	p.mu.Lock()
	route := strings.TrimPrefix(r.URL.Path, api.RoutePrefix)
	p.calls[route]++
	unsigned := p.unsigned
	response := p.answer(route, body)
	p.mu.Unlock()
	if unsigned {
		w.WriteHeader(http.StatusUnauthorized)
		return
	}
	timestamp := strconv.FormatInt(time.Now().Unix(), 10)
	responseEnvelope, _ := api.NewEnvelope(r.URL.Path, response, timestamp, headers.Nonce)
	signature, _ := api.SignResponse(p.key, http.StatusOK, responseEnvelope)
	w.Header().Set(api.HeaderTimestamp, timestamp)
	w.Header().Set(api.HeaderRequestNonce, headers.Nonce)
	w.Header().Set(api.HeaderSignature, signature)
	w.Header().Set("Content-Type", "application/json")
	_, _ = w.Write(response)
}

func (p *controlPlane) answer(route string, body []byte) []byte {
	now := time.Now().UTC()
	wire := func(d time.Duration) string { return now.Add(d).Format(time.RFC3339) }
	switch {
	case route == "/heartbeat":
		var request api.HostHeartbeatRequest
		_ = json.Unmarshal(body, &request)
		p.heartbeats = append(p.heartbeats, request)
		return []byte(`{"serverTime":"` + wire(0) + `","pollAfterSeconds":1}`)
	case route == "/jobs/claim" && p.queued:
		p.queued = false
		job := api.ClaimedJob{ID: jobID, ClaimToken: token, LeaseVersion: 1, LeaseExpiresAt: wire(30 * time.Second),
			PhaseDeadline: wire(10 * time.Minute), ExecutionDeadline: wire(10*time.Minute - 15*time.Second),
			StopDeadline: wire(10*time.Minute - 10*time.Second), Phase: "pre", IdempotencyClass: "non_retryable",
			Action: sleepAction(p.t), Parameters: map[string]any{}}
		raw, _ := json.Marshal(api.ClaimResponse{Job: &job})
		return raw
	case route == "/jobs/claim":
		return []byte(`{"job":null}`)
	case strings.HasSuffix(route, "/start"):
		return []byte(`{"leaseVersion":2,"leaseExpiresAt":"` + wire(30*time.Second) + `","phaseDeadline":"` + wire(10*time.Minute) +
			`","executionDeadline":"` + wire(10*time.Minute-15*time.Second) + `","stopDeadline":"` + wire(10*time.Minute-10*time.Second) + `"}`)
	case strings.HasSuffix(route, "/heartbeat"):
		return []byte(`{"leaseVersion":2,"leaseExpiresAt":"` + wire(30*time.Second) + `","stopRequested":false}`)
	default:
		var request api.JobCompleteRequest
		_ = json.Unmarshal(body, &request)
		p.completed = append(p.completed, request)
		// The result must be durable before it is reported.
		raw, err := os.ReadFile(filepath.Join(p.state, "jobs", jobID+".json"))
		p.journal = append(p.journal, string(raw)+errString(err))
		return []byte(`{"accepted":true,"terminalStatus":"` + request.Outcome + `"}`)
	}
}

func errString(err error) string {
	if err == nil {
		return ""
	}
	return err.Error()
}

func (p *controlPlane) count(route string) int {
	p.mu.Lock()
	defer p.mu.Unlock()
	return p.calls[route]
}

// sleepAction is a valid V1 action whose only step the fake primitive turns
// into a long-running child process.
func sleepAction(t *testing.T) api.ClaimedAction {
	definition, err := action.Decode([]byte(`{"version":1,"steps":[{"primitive":"docker.container.exec","containerAlias":"app","commandAlias":"backup","arguments":{}}]}`))
	if err != nil {
		t.Fatal(err)
	}
	canonical, err := action.Canonical(definition)
	if err != nil {
		t.Fatal(err)
	}
	hash, err := action.Hash(definition)
	if err != nil {
		t.Fatal(err)
	}
	return api.ClaimedAction{DefinitionCanonical: canonical, ActionHash: hash}
}

// sleeper starts the helper process and reports when it runs.
type sleeper struct {
	binary  string
	started chan struct{}
	once    sync.Once
}

func (s *sleeper) Run(ctx context.Context, step executor.StepContext) error {
	s.once.Do(func() { close(s.started) })
	_, err := step.Run(ctx, executor.Command{Path: s.binary, Args: []string{"muwa-helper", "sleep"}})
	return err
}

func testBinary(t *testing.T) string {
	t.Helper()
	path, err := filepath.EvalSymlinks(os.Args[0])
	if err != nil {
		t.Fatal(err)
	}
	abs, err := filepath.Abs(path)
	if err != nil {
		t.Fatal(err)
	}
	return abs
}

func options(t *testing.T, h host, s *sleeper) Options {
	processes := executor.NewProcessRunner()
	processes.Executables = map[string]bool{s.binary: true}
	processes.TermGrace = time.Second
	return Options{
		ConfigPath:    h.config,
		Primitives:    map[string]executor.Primitive{"docker.container.exec": s},
		Processes:     processes,
		ShutdownGrace: 500 * time.Millisecond,
	}
}

// start runs Main in the background and returns its exit code channel.
func start(opts Options, logs io.Writer) <-chan int {
	exit := make(chan int, 1)
	go func() { exit <- Main(opts, logs) }()
	return exit
}

func waitExit(t *testing.T, exit <-chan int, within time.Duration) int {
	t.Helper()
	select {
	case code := <-exit:
		return code
	case <-time.After(within):
		t.Fatalf("agent did not exit within %s", within)
		return -1
	}
}

func TestSigtermStopsTheRunningProcessJournalsAndCompletesBeforeExit(t *testing.T) {
	plane, server := newControlPlane(t, true)
	h := newHost(t, server.URL)
	plane.state = h.state
	s := &sleeper{binary: testBinary(t), started: make(chan struct{})}
	var logs syncBuffer

	exit := start(options(t, h, s), &logs)
	select {
	case <-s.started:
	case <-time.After(10 * time.Second):
		t.Fatalf("job did not start; logs:\n%s", logs.String())
	}
	time.Sleep(200 * time.Millisecond) // let the helper process come up
	if err := syscall.Kill(os.Getpid(), syscall.SIGTERM); err != nil {
		t.Fatal(err)
	}

	if code := waitExit(t, exit, 15*time.Second); code != 0 {
		t.Fatalf("exit code %d; logs:\n%s", code, logs.String())
	}
	plane.mu.Lock()
	defer plane.mu.Unlock()
	if len(plane.completed) != 1 || plane.completed[0].Outcome != "timed_out" || plane.completed[0].LeaseVersion != 2 {
		t.Fatalf("completions %+v; logs:\n%s", plane.completed, logs.String())
	}
	if !strings.Contains(plane.journal[0], `"state":"completed"`) {
		t.Fatalf("completion was sent before it was journalled: %q", plane.journal[0])
	}
	if _, err := os.Stat(filepath.Join(h.state, "jobs", jobID+".json")); !os.IsNotExist(err) {
		t.Fatalf("accepted completion must remove the journal record, stat: %v", err)
	}
	if plane.calls["/jobs/claim"] != 1 {
		t.Fatalf("no new job may be claimed after SIGTERM, claims: %d", plane.calls["/jobs/claim"])
	}
}

func TestHostHeartbeatReportsVersionAndPrimitivesAsCapabilities(t *testing.T) {
	plane, server := newControlPlane(t, false)
	h := newHost(t, server.URL)
	s := &sleeper{binary: testBinary(t), started: make(chan struct{})}
	opts := options(t, h, s)
	opts.Primitives = nil // the real V1 primitives
	var logs syncBuffer
	ctx, cancel := context.WithCancel(context.Background())

	done := make(chan error, 1)
	go func() { done <- Run(ctx, nil, opts, newLogger(&logs)) }()
	deadline := time.Now().Add(5 * time.Second)
	for plane.count("/heartbeat") == 0 || plane.count("/jobs/claim") == 0 {
		if time.Now().After(deadline) {
			t.Fatalf("no heartbeat and claim; logs:\n%s", logs.String())
		}
		time.Sleep(20 * time.Millisecond)
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}

	plane.mu.Lock()
	defer plane.mu.Unlock()
	beat := plane.heartbeats[0]
	want := []string{"docker.container.exec", "file.retention", "file.write-pipeline", "filesystem.acl.read",
		"filesystem.assert-readable", "service.control", "service.wait-http"}
	if beat.AgentVersion != version.Version || beat.Platform != "linux" || strings.Join(beat.Capabilities, ",") != strings.Join(want, ",") {
		t.Fatalf("heartbeat %+v", beat)
	}
}

func TestStartFailsClosedBeforeAnyRequestOnUnsafeFiles(t *testing.T) {
	type unsafe struct {
		reason  string
		breakIt func(t *testing.T, h host)
	}
	cases := map[string]unsafe{
		"group-readable config": {"config: ", func(t *testing.T, h host) { chmod(t, h.config, 0o640) }},
		"world-readable secret": {"secret file: ", func(t *testing.T, h host) { chmod(t, filepath.Join(h.dir, "secret"), 0o644) }},
		"group-writable policy": {"policy: ", func(t *testing.T, h host) { chmod(t, h.policy, 0o664) }},
		"group-writable state":  {"journal: state directory", func(t *testing.T, h host) { chmod(t, h.state, 0o770) }},
		"symlinked policy": {"without following symlinks", func(t *testing.T, h host) {
			target := filepath.Join(h.dir, "real-policy.yaml")
			write(t, target, "version: 1\n", 0o644)
			if err := os.Remove(h.policy); err != nil {
				t.Fatal(err)
			}
			if err := os.Symlink(target, h.policy); err != nil {
				t.Fatal(err)
			}
		}},
		"writable parent": {"writable by group or others", func(t *testing.T, h host) { chmod(t, h.dir, 0o777) }},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			plane, server := newControlPlane(t, false)
			h := newHost(t, server.URL)
			c.breakIt(t, h)
			s := &sleeper{binary: testBinary(t), started: make(chan struct{})}
			var logs syncBuffer

			code := waitExit(t, start(options(t, h, s), &logs), 5*time.Second)

			if code != 1 || !strings.Contains(logs.String(), "agent cannot start") || !strings.Contains(logs.String(), c.reason) {
				t.Fatalf("exit code %d, logs:\n%s", code, logs.String())
			}
			if plane.count("/heartbeat")+plane.count("/jobs/claim") != 0 {
				t.Fatal("an unsafe installation must not contact the control plane")
			}
			if strings.Contains(logs.String(), secret) {
				t.Fatal("secret leaked into the log")
			}
		})
	}
}

func chmod(t *testing.T, path string, mode os.FileMode) {
	t.Helper()
	if err := os.Chmod(path, mode); err != nil {
		t.Fatal(err)
	}
}

func TestRevokedKeyEndsTheAgentWithAFailure(t *testing.T) {
	plane, server := newControlPlane(t, false)
	plane.unsigned = true
	h := newHost(t, server.URL)
	s := &sleeper{binary: testBinary(t), started: make(chan struct{})}
	var logs syncBuffer

	code := waitExit(t, start(options(t, h, s), &logs), 10*time.Second)

	if code != 1 || !strings.Contains(logs.String(), "not accepted") {
		t.Fatalf("exit code %d, logs:\n%s", code, logs.String())
	}
}

func TestSighupReloadsThePolicyAndKeepsTheSnapshotOnError(t *testing.T) {
	_, server := newControlPlane(t, false)
	h := newHost(t, server.URL)
	s := &sleeper{binary: testBinary(t), started: make(chan struct{})}
	var logs syncBuffer
	exit := start(options(t, h, s), &logs)
	waitFor(t, &logs, "agent started")

	write(t, h.policy, "version: 1\nunknownSection: {}\n", 0o644)
	if err := syscall.Kill(os.Getpid(), syscall.SIGHUP); err != nil {
		t.Fatal(err)
	}
	waitFor(t, &logs, "policy reload failed, previous policy stays active")

	write(t, h.policy, "version: 1\n", 0o644)
	if err := syscall.Kill(os.Getpid(), syscall.SIGHUP); err != nil {
		t.Fatal(err)
	}
	waitFor(t, &logs, "policy reloaded")

	if err := syscall.Kill(os.Getpid(), syscall.SIGTERM); err != nil {
		t.Fatal(err)
	}
	if code := waitExit(t, exit, 10*time.Second); code != 0 {
		t.Fatalf("exit code %d; logs:\n%s", code, logs.String())
	}
}

func waitFor(t *testing.T, logs *syncBuffer, text string) {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for !strings.Contains(logs.String(), text) {
		if time.Now().After(deadline) {
			t.Fatalf("log line %q missing; logs:\n%s", text, logs.String())
		}
		time.Sleep(20 * time.Millisecond)
	}
}

type syncBuffer struct {
	mu  sync.Mutex
	buf bytes.Buffer
}

func (b *syncBuffer) Write(p []byte) (int, error) {
	b.mu.Lock()
	defer b.mu.Unlock()
	return b.buf.Write(p)
}

func (b *syncBuffer) String() string {
	b.mu.Lock()
	defer b.mu.Unlock()
	return b.buf.String()
}
