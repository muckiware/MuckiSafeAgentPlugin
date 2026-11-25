// Command fakeplane is the control plane of the Linux E2E tests. It answers
// the five agent routes signed with MUWA-HMAC-V1 exactly like the PHP plugin
// and offers unsigned test routes on the same loopback listener:
//
//	POST /_e2e/enqueue  {"definition":{...},"idempotencyClass":"safe","executionSeconds":120}
//	POST /_e2e/stop     {"jobId":"..."}  requests a stop through the next lease heartbeat
//	GET  /_e2e/status   heartbeats, claims and completions seen so far
//
// It is test support only and never part of a release.
package main

import (
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/action"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
)

func main() {
	listen := flag.String("listen", "127.0.0.1:18080", "listen address (loopback only)")
	keyID := flag.String("key-id", "", "agent key id")
	secretFile := flag.String("secret-file", "", "file with the base64url agent secret")
	flag.Parse()
	raw, err := os.ReadFile(*secretFile)
	if err != nil {
		log.Fatal(err)
	}
	key, err := api.DecodeSecret(strings.TrimSuffix(string(raw), "\n"))
	if err != nil {
		log.Fatal(err)
	}
	plane := newPlane(*keyID, key)
	log.Printf("fakeplane listening on %s", *listen)
	log.Fatal(http.ListenAndServe(*listen, plane))
}

type job struct {
	claimed api.ClaimedJob
	stop    bool
}

// Status is what /_e2e/status reports.
type Status struct {
	Heartbeats  []api.HostHeartbeatRequest `json:"heartbeats"`
	Claims      int                        `json:"claims"`
	Started     []string                   `json:"started"`
	Completions []Completion               `json:"completions"`
}

// Completion is one accepted completion request.
type Completion struct {
	JobID string `json:"jobId"`
	api.JobCompleteRequest
}

type plane struct {
	keyID  string
	key    []byte
	mu     sync.Mutex
	queue  []*job
	jobs   map[string]*job
	status Status
}

func newPlane(keyID string, key []byte) *plane {
	return &plane{keyID: keyID, key: key, jobs: map[string]*job{},
		status: Status{Heartbeats: []api.HostHeartbeatRequest{}, Started: []string{}, Completions: []Completion{}}}
}

func (p *plane) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	body, err := io.ReadAll(io.LimitReader(r.Body, api.TransportHardCapBytes))
	if err != nil {
		w.WriteHeader(http.StatusBadRequest)
		return
	}
	p.mu.Lock()
	defer p.mu.Unlock()
	if strings.HasPrefix(r.URL.Path, "/_e2e/") {
		p.control(w, r.URL.Path, body)
		return
	}
	headers, err := api.ParseRequestHeaders(r.Header)
	envelope, envErr := api.NewEnvelope(r.URL.Path, body, headers.Timestamp, headers.Nonce)
	if err != nil || envErr != nil || headers.KeyID != p.keyID || !api.VerifyRequest(p.key, r.Method, envelope, headers.Signature) {
		w.WriteHeader(http.StatusUnauthorized)
		return
	}
	response, ok := p.answer(strings.TrimPrefix(r.URL.Path, api.RoutePrefix), body)
	if !ok {
		w.WriteHeader(http.StatusNotFound)
		return
	}
	p.sign(w, r.URL.Path, headers.Nonce, response)
}

func (p *plane) sign(w http.ResponseWriter, path, nonce string, response []byte) {
	timestamp := strconv.FormatInt(time.Now().Unix(), 10)
	envelope, _ := api.NewEnvelope(path, response, timestamp, nonce)
	signature, _ := api.SignResponse(p.key, http.StatusOK, envelope)
	w.Header().Set(api.HeaderTimestamp, timestamp)
	w.Header().Set(api.HeaderRequestNonce, nonce)
	w.Header().Set(api.HeaderSignature, signature)
	w.Header().Set("Content-Type", "application/json")
	_, _ = w.Write(response)
}

func wire(t time.Time) string { return t.UTC().Format(time.RFC3339) }

func earlier(a, b time.Time) time.Time {
	if a.Before(b) {
		return a
	}
	return b
}

func (p *plane) answer(route string, body []byte) ([]byte, bool) {
	if route == "/heartbeat" || route == "/jobs/claim" {
		return p.answerHost(route, body), true
	}
	id := strings.Split(strings.TrimPrefix(route, "/jobs/"), "/")[0]
	current := p.jobs[id]
	if current == nil {
		return nil, false
	}
	return p.answerJob(route, id, current, body), true
}

func (p *plane) answerHost(route string, body []byte) []byte {
	now := time.Now()
	switch route {
	case "/heartbeat":
		var request api.HostHeartbeatRequest
		_ = json.Unmarshal(body, &request)
		p.status.Heartbeats = append(p.status.Heartbeats, request)
		return []byte(`{"serverTime":"` + wire(now) + `","pollAfterSeconds":10}`)
	default:
		p.status.Claims++
		if len(p.queue) == 0 {
			return []byte(`{"job":null}`)
		}
		next := p.queue[0]
		p.queue = p.queue[1:]
		raw, _ := json.Marshal(api.ClaimResponse{Job: &next.claimed})
		return raw
	}
}

func (p *plane) answerJob(route, id string, current *job, body []byte) []byte {
	now := time.Now()
	phase, _ := time.Parse(time.RFC3339, current.claimed.PhaseDeadline)
	execution, _ := time.Parse(time.RFC3339, current.claimed.ExecutionDeadline)
	switch {
	case strings.HasSuffix(route, "/start"):
		p.status.Started = append(p.status.Started, id)
		return []byte(fmt.Sprintf(`{"leaseVersion":2,"leaseExpiresAt":"%s","phaseDeadline":"%s","executionDeadline":"%s","stopDeadline":"%s"}`,
			wire(now.Add(30*time.Second)), current.claimed.PhaseDeadline, current.claimed.ExecutionDeadline, current.claimed.StopDeadline))
	case strings.HasSuffix(route, "/heartbeat") && current.stop:
		return []byte(fmt.Sprintf(`{"leaseVersion":2,"leaseExpiresAt":"%s","stopRequested":true,"stopDeadline":"%s"}`,
			wire(now.Add(30*time.Second)), wire(earlier(now.Add(5*time.Second), execution.Add(5*time.Second)))))
	case strings.HasSuffix(route, "/heartbeat"):
		return []byte(`{"leaseVersion":2,"leaseExpiresAt":"` + wire(earlier(now.Add(30*time.Second), phase)) + `","stopRequested":false}`)
	}
	var request api.JobCompleteRequest
	_ = json.Unmarshal(body, &request)
	p.status.Completions = append(p.status.Completions, Completion{JobID: id, JobCompleteRequest: request})
	terminal := map[string]string{"succeeded": "succeeded", "failed": "failed", "timed_out": "timed_out", "ambiguous": "unknown"}[request.Outcome]
	return []byte(`{"accepted":true,"terminalStatus":"` + terminal + `"}`)
}

type enqueueRequest struct {
	Definition       json.RawMessage `json:"definition"`
	IdempotencyClass string          `json:"idempotencyClass"`
	ExecutionSeconds int             `json:"executionSeconds"`
}

func (p *plane) control(w http.ResponseWriter, path string, body []byte) {
	w.Header().Set("Content-Type", "application/json")
	switch path {
	case "/_e2e/status":
		_ = json.NewEncoder(w).Encode(p.status)
	case "/_e2e/enqueue":
		id, err := p.enqueue(body)
		if err != nil {
			w.WriteHeader(http.StatusBadRequest)
			_ = json.NewEncoder(w).Encode(map[string]string{"error": err.Error()})
			return
		}
		_ = json.NewEncoder(w).Encode(map[string]string{"jobId": id})
	case "/_e2e/stop":
		var request struct {
			JobID string `json:"jobId"`
		}
		if json.Unmarshal(body, &request) != nil || p.jobs[request.JobID] == nil {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		p.jobs[request.JobID].stop = true
	default:
		w.WriteHeader(http.StatusNotFound)
	}
}

// enqueue validates and canonicalizes the action like the PHP editor and
// materializes the deadlines: execution = phase - 15s, stop = execution + 5s.
func (p *plane) enqueue(body []byte) (string, error) {
	var request enqueueRequest
	if err := json.Unmarshal(body, &request); err != nil {
		return "", err
	}
	definition, err := action.Decode(request.Definition)
	if err != nil {
		return "", err
	}
	canonical, err := action.Canonical(definition)
	if err != nil {
		return "", err
	}
	hash, err := action.Hash(definition)
	if err != nil {
		return "", err
	}
	if request.IdempotencyClass == "" {
		request.IdempotencyClass = "non_retryable"
	}
	execution := time.Now().Add(time.Duration(max(request.ExecutionSeconds, 30)) * time.Second)
	id, token := uuid(), randomToken()
	p.jobs[id] = &job{claimed: api.ClaimedJob{ID: id, ClaimToken: token, LeaseVersion: 1,
		LeaseExpiresAt: wire(time.Now().Add(30 * time.Second)), PhaseDeadline: wire(execution.Add(15 * time.Second)),
		ExecutionDeadline: wire(execution), StopDeadline: wire(execution.Add(5 * time.Second)), Phase: "pre",
		IdempotencyClass: request.IdempotencyClass, Action: api.ClaimedAction{DefinitionCanonical: canonical, ActionHash: hash},
		Parameters: map[string]any{}}}
	p.queue = append(p.queue, p.jobs[id])
	return id, nil
}

func uuid() string {
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	b[6] = b[6]&0x0f | 0x40
	b[8] = b[8]&0x3f | 0x80
	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:])
}

func randomToken() string {
	b := make([]byte, 32)
	_, _ = rand.Read(b)
	return base64.RawURLEncoding.EncodeToString(b)
}
