package executor

import (
	"bytes"
	"encoding/base64"
	"sort"
	"sync"
	"unicode/utf8"
)

// Redacted replaces every known secret value in output and summaries.
const Redacted = "[REDACTED]"

// TailLimit is the byte limit per stream (implementation plan 2.8).
const TailLimit = 65536

// minSecretLength keeps trivially short values from redacting everything.
const minSecretLength = 8

// Redactor replaces known secret values, longest first.
type Redactor struct {
	secrets [][]byte
	maxLen  int
}

// NewRedactor ignores empty and too short values.
func NewRedactor(secrets ...string) *Redactor {
	r := &Redactor{}
	for _, s := range secrets {
		if len(s) >= minSecretLength {
			r.secrets = append(r.secrets, []byte(s))
			r.maxLen = max(r.maxLen, len(s))
		}
	}
	sort.Slice(r.secrets, func(i, j int) bool { return len(r.secrets[i]) > len(r.secrets[j]) })
	return r
}

// String redacts a complete value such as a summary.
func (r *Redactor) String(s string) string {
	out, _ := r.redact([]byte(s), true)
	return string(out)
}

// redact replaces secrets in data. Unless final, it stops before the last
// maxLen-1 bytes, which might start a secret continued by the next chunk,
// and returns them as the unprocessed remainder.
func (r *Redactor) redact(data []byte, final bool) (out, rest []byte) {
	if len(r.secrets) == 0 {
		return data, nil
	}
	cut := len(data)
	if !final {
		cut -= r.maxLen - 1
	}
	i := 0
	for i < cut {
		matched := false
		for _, secret := range r.secrets {
			if bytes.HasPrefix(data[i:], secret) {
				out = append(out, Redacted...)
				i += len(secret)
				matched = true
				break
			}
		}
		if !matched {
			out = append(out, data[i])
			i++
		}
	}
	return out, append([]byte(nil), data[i:]...)
}

// Tail is an io.Writer that redacts a stream across chunk boundaries and
// keeps only its last limit bytes. Redaction happens before the limit is
// applied, so a cut can never expose a fragment of a secret.
type Tail struct {
	mu       sync.Mutex
	redactor *Redactor
	limit    int
	pending  []byte
	data     []byte
}

// NewTail creates a tail of at most limit bytes.
func NewTail(limit int, redactor *Redactor) *Tail {
	return &Tail{redactor: redactor, limit: limit}
}

// Write never fails; output beyond the limit is dropped from the front.
func (t *Tail) Write(p []byte) (int, error) {
	t.mu.Lock()
	defer t.mu.Unlock()
	out, rest := t.redactor.redact(append(t.pending, p...), false)
	t.pending = rest
	t.keep(out)
	return len(p), nil
}

func (t *Tail) keep(out []byte) {
	t.data = append(t.data, out...)
	if len(t.data) > 2*t.limit {
		t.data = append([]byte(nil), t.data[len(t.data)-t.limit:]...)
	}
}

// Encoded flushes the stream and returns the tail as UTF-8 or, for binary
// output, as base64. An empty stream yields nil, nil.
func (t *Tail) Encoded() (*string, *string) {
	t.mu.Lock()
	defer t.mu.Unlock()
	out, _ := t.redactor.redact(t.pending, true)
	t.pending = nil
	t.keep(out)
	data := t.data
	if len(data) > t.limit {
		data = data[len(data)-t.limit:]
	}
	if len(data) == 0 {
		return nil, nil
	}
	// The byte cut may split a multi-byte character at the front: drop at
	// most three leading continuation bytes before deciding on the encoding.
	drop := 0
	for drop < 3 && drop < len(data)-1 && data[drop]&0xC0 == 0x80 {
		drop++
	}
	if utf8.Valid(data[drop:]) {
		text, encoding := string(data[drop:]), "utf-8"
		return &text, &encoding
	}
	text, encoding := base64.StdEncoding.EncodeToString(data), "base64"
	return &text, &encoding
}
