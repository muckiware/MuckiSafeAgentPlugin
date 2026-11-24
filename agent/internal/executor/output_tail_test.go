package executor

import (
	"encoding/base64"
	"strings"
	"testing"
)

const secret = "s3cr3t-t0ken-value"

func written(t *Tail, chunks ...string) (string, string) {
	for _, chunk := range chunks {
		if _, err := t.Write([]byte(chunk)); err != nil {
			panic(err)
		}
	}
	tail, encoding := t.Encoded()
	if tail == nil {
		return "", ""
	}
	return *tail, *encoding
}

func TestSecretsAreRedactedWithinAndAcrossChunks(t *testing.T) {
	redactor := NewRedactor(secret, "other-secret")
	cases := map[string][]string{
		"single chunk": {"before " + secret + " after"},
		"split":        {"before s3cr3t-t0", "ken-value after"},
		"byte by byte": strings.Split("before "+secret+" after", ""),
		"at the start": {secret, " after"},
		"at the end":   {"before ", secret},
	}
	for name, chunks := range cases {
		t.Run(name, func(t *testing.T) {
			tail, encoding := written(NewTail(1024, redactor), chunks...)
			if strings.Contains(tail, "t0ken") || !strings.Contains(tail, Redacted) || encoding != "utf-8" {
				t.Fatalf("%q (%s)", tail, encoding)
			}
		})
	}
	tail, _ := written(NewTail(1024, redactor), "a other-secret b "+secret+" c")
	if tail != "a "+Redacted+" b "+Redacted+" c" {
		t.Fatalf("%q", tail)
	}
}

func TestRedactionHappensBeforeTheByteLimit(t *testing.T) {
	tail, _ := written(NewTail(12, NewRedactor(secret)), strings.Repeat("x", 100), secret, "yy")
	if len(tail) > 12 {
		t.Fatalf("tail exceeds the byte limit: %q", tail)
	}
	for i := 0; i+4 <= len(secret); i++ {
		if strings.Contains(tail, secret[i:i+4]) {
			t.Fatalf("fragment %q of the secret leaked: %q", secret[i:i+4], tail)
		}
	}
}

func TestTailKeepsTheLastBytes(t *testing.T) {
	tail, encoding := written(NewTail(65536, NewRedactor()), strings.Repeat("a", 70000), "END")
	if len(tail) != 65536 || !strings.HasSuffix(tail, "END") || encoding != "utf-8" {
		t.Fatalf("len %d, encoding %s", len(tail), encoding)
	}
}

func TestMultiByteCharacterCutAtTheStartStaysUTF8(t *testing.T) {
	tail, encoding := written(NewTail(4, NewRedactor()), "äöü")
	if encoding != "utf-8" || tail != "öü" {
		t.Fatalf("%q (%s)", tail, encoding)
	}
}

func TestInvalidUTF8IsBase64Encoded(t *testing.T) {
	tail, encoding := written(NewTail(64, NewRedactor()), "ok\xff\xfebinary")
	decoded, err := base64.StdEncoding.DecodeString(tail)
	if encoding != "base64" || err != nil || string(decoded) != "ok\xff\xfebinary" {
		t.Fatalf("%q (%s) %v", tail, encoding, err)
	}
}

func TestEmptyStreamHasNoTail(t *testing.T) {
	tail, encoding := NewTail(64, NewRedactor()).Encoded()
	if tail != nil || encoding != nil {
		t.Fatal("an empty stream must not produce a tail")
	}
}

func TestShortSecretsAreNotUsedForRedaction(t *testing.T) {
	tail, _ := written(NewTail(64, NewRedactor("", "ab")), "abcabc")
	if tail != "abcabc" {
		t.Fatalf("%q", tail)
	}
}

func TestRedactorRedactsSummaries(t *testing.T) {
	if got := NewRedactor(secret).String("failed with " + secret); got != "failed with "+Redacted {
		t.Fatalf("%q", got)
	}
}
