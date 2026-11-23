package action

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"unicode/utf8"
)

const (
	maxDepth          = 32
	maxCanonicalBytes = 65536
	maxRawBytes       = 262144
)

var aliasPattern = regexp.MustCompile(`^[a-z][a-z0-9]*(-[a-z0-9]+)*$`)

// Decode strictly decodes one action object. It rejects invalid UTF-8, BOMs,
// duplicate members, trailing values and non-integer number forms.
func Decode(raw []byte) (map[string]any, error) {
	if len(raw) == 0 || len(raw) > maxRawBytes || !utf8.Valid(raw) || bytes.HasPrefix(raw, []byte{0xef, 0xbb, 0xbf}) {
		return nil, errors.New("action must be non-BOM valid UTF-8 JSON")
	}
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()
	decoded, err := decodeValue(decoder, 0)
	if err != nil {
		return nil, fmt.Errorf("decode action: %w", err)
	}
	if _, err := decoder.Token(); err != io.EOF {
		return nil, errors.New("trailing JSON value")
	}
	definition, ok := decoded.(map[string]any)
	if !ok {
		return nil, errors.New("action must be an object")
	}
	if err := Validate(definition); err != nil {
		return nil, err
	}
	return definition, nil
}

func decodeValue(decoder *json.Decoder, depth int) (any, error) {
	if depth > maxDepth {
		return nil, errors.New("JSON nesting exceeds 32 levels")
	}
	token, err := decoder.Token()
	if err != nil {
		return nil, err
	}
	delimiter, isDelimiter := token.(json.Delim)
	if !isDelimiter {
		if number, ok := token.(json.Number); ok {
			if number.String() == "-0" || strings.ContainsAny(number.String(), ".eE") {
				return nil, errors.New("only canonical integers are allowed")
			}
			if _, err := strconv.ParseInt(number.String(), 10, 64); err != nil {
				return nil, errors.New("integer outside signed 64-bit range")
			}
		}
		return token, nil
	}
	switch delimiter {
	case '{':
		object := map[string]any{}
		for decoder.More() {
			keyToken, err := decoder.Token()
			if err != nil {
				return nil, err
			}
			key, ok := keyToken.(string)
			if !ok {
				return nil, errors.New("object key must be a string")
			}
			if _, duplicate := object[key]; duplicate {
				return nil, fmt.Errorf("duplicate object member %q", key)
			}
			value, err := decodeValue(decoder, depth+1)
			if err != nil {
				return nil, err
			}
			object[key] = value
		}
		if closing, err := decoder.Token(); err != nil || closing != json.Delim('}') {
			return nil, errors.New("unterminated object")
		}
		return object, nil
	case '[':
		array := []any{}
		for decoder.More() {
			value, err := decodeValue(decoder, depth+1)
			if err != nil {
				return nil, err
			}
			array = append(array, value)
		}
		if closing, err := decoder.Token(); err != nil || closing != json.Delim(']') {
			return nil, errors.New("unterminated array")
		}
		return array, nil
	default:
		return nil, errors.New("unexpected JSON delimiter")
	}
}

func Validate(definition map[string]any) error {
	if err := exactFields(definition, []string{"version", "steps"}, nil, "$"); err != nil {
		return err
	}
	version, ok := definition["version"].(json.Number)
	if !ok || version.String() != "1" {
		return errors.New("$.version must be integer 1")
	}
	steps, ok := definition["steps"].([]any)
	if !ok || len(steps) < 1 || len(steps) > 64 {
		return errors.New("$.steps must contain between 1 and 64 steps")
	}
	for index, raw := range steps {
		step, ok := raw.(map[string]any)
		if !ok {
			return fmt.Errorf("$.steps[%d] must be an object", index)
		}
		if err := validateStep(step, index); err != nil {
			return err
		}
	}
	canonical, err := Canonical(definition)
	if err != nil {
		return err
	}
	if len(canonical) > maxCanonicalBytes {
		return errors.New("canonical action exceeds 65536 bytes")
	}
	return nil
}

func validateStep(step map[string]any, index int) error {
	primitive, ok := step["primitive"].(string)
	if !ok {
		return fmt.Errorf("$.steps[%d].primitive is unknown", index)
	}
	path := fmt.Sprintf("$.steps[%d]", index)
	switch primitive {
	case "service.control":
		if err := exactFields(step, []string{"primitive", "serviceAlias", "operation"}, nil, path); err != nil {
			return err
		}
		if err := alias(step["serviceAlias"], path+".serviceAlias"); err != nil {
			return err
		}
		operation := step["operation"]
		if operation != "start" && operation != "stop" && operation != "restart" {
			return errors.New(path + ".operation must be start, stop or restart")
		}
	case "service.wait-http":
		if err := exactFields(step, []string{"primitive", "endpointAlias", "expectedStatuses"}, nil, path); err != nil {
			return err
		}
		if err := alias(step["endpointAlias"], path+".endpointAlias"); err != nil {
			return err
		}
		if err := statuses(step["expectedStatuses"], path+".expectedStatuses"); err != nil {
			return err
		}
	case "docker.container.exec":
		if err := exactFields(step, []string{"primitive", "containerAlias", "commandAlias", "arguments"}, nil, path); err != nil {
			return err
		}
		if err := alias(step["containerAlias"], path+".containerAlias"); err != nil {
			return err
		}
		if err := alias(step["commandAlias"], path+".commandAlias"); err != nil {
			return err
		}
		arguments, ok := step["arguments"].(map[string]any)
		if !ok || len(arguments) != 0 {
			return errors.New(path + ".arguments must be an empty object in V1")
		}
	case "filesystem.acl.read":
		if err := exactFields(step, []string{"primitive", "pathAlias", "principalAlias"}, []string{"relativePath"}, path); err != nil {
			return err
		}
		if err := alias(step["pathAlias"], path+".pathAlias"); err != nil {
			return err
		}
		if err := alias(step["principalAlias"], path+".principalAlias"); err != nil {
			return err
		}
		if value, exists := step["relativePath"]; exists {
			if err := relativePath(value, path+".relativePath"); err != nil {
				return err
			}
		}
	case "filesystem.assert-readable":
		if err := exactFields(step, []string{"primitive", "checkAlias"}, nil, path); err != nil {
			return err
		}
		if err := alias(step["checkAlias"], path+".checkAlias"); err != nil {
			return err
		}
	case "file.write-pipeline":
		if err := exactFields(step, []string{"primitive", "sourceCommandAlias", "destinationAlias", "transforms", "nameFromRunId"}, nil, path); err != nil {
			return err
		}
		if err := alias(step["sourceCommandAlias"], path+".sourceCommandAlias"); err != nil {
			return err
		}
		if err := alias(step["destinationAlias"], path+".destinationAlias"); err != nil {
			return err
		}
		transforms, ok := step["transforms"].([]any)
		if !ok || len(transforms) != 1 || transforms[0] != "gzip" {
			return errors.New(path + ".transforms must be exactly [\"gzip\"]")
		}
		if step["nameFromRunId"] != true {
			return errors.New(path + ".nameFromRunId must be true")
		}
	case "file.retention":
		if err := exactFields(step, []string{"primitive", "pathAlias", "manifestAlias", "keep"}, nil, path); err != nil {
			return err
		}
		if err := alias(step["pathAlias"], path+".pathAlias"); err != nil {
			return err
		}
		if err := alias(step["manifestAlias"], path+".manifestAlias"); err != nil {
			return err
		}
		number, ok := step["keep"].(json.Number)
		if !ok {
			return errors.New(path + ".keep must be an integer from 1 to 10000")
		}
		parsed, err := strconv.ParseInt(number.String(), 10, 64)
		if err != nil || parsed < 1 || parsed > 10000 {
			return errors.New(path + ".keep must be an integer from 1 to 10000")
		}
	default:
		return errors.New(path + ".primitive is unknown")
	}
	return nil
}

func alias(value any, path string) error {
	text, ok := value.(string)
	if !ok || len(text) > 64 || !aliasPattern.MatchString(text) {
		return errors.New(path + " is not a canonical policy alias")
	}
	return nil
}

func relativePath(value any, path string) error {
	text, ok := value.(string)
	if !ok || text == "" || len(text) > 4096 || !utf8.ValidString(text) || strings.HasPrefix(text, "/") || strings.Contains(text, "\\") {
		return errors.New(path + " is not a safe relative path")
	}
	for _, character := range text {
		if character < 0x20 || character == 0x7f {
			return errors.New(path + " is not a safe relative path")
		}
	}
	if text == "." {
		return nil
	}
	for _, component := range strings.Split(text, "/") {
		if component == "" || component == "." || component == ".." {
			return errors.New(path + " is not a safe relative path")
		}
	}
	return nil
}

func statuses(value any, path string) error {
	items, ok := value.([]any)
	if !ok || len(items) < 1 || len(items) > 16 {
		return errors.New(path + " must contain 1 to 16 statuses")
	}
	previous := int64(99)
	for _, item := range items {
		number, ok := item.(json.Number)
		if !ok {
			return errors.New(path + " must contain unique ascending integers from 100 to 599")
		}
		status, err := strconv.ParseInt(number.String(), 10, 64)
		if err != nil || status < 100 || status > 599 || status <= previous {
			return errors.New(path + " must contain unique ascending integers from 100 to 599")
		}
		previous = status
	}
	return nil
}

func Canonical(value any) (string, error) {
	var buffer bytes.Buffer
	if err := appendCanonical(&buffer, value, 0); err != nil {
		return "", err
	}
	return buffer.String(), nil
}

func Hash(definition map[string]any) (string, error) {
	if err := Validate(definition); err != nil {
		return "", err
	}
	canonical, err := Canonical(definition)
	if err != nil {
		return "", err
	}
	sum := sha256.Sum256([]byte(canonical))
	return hex.EncodeToString(sum[:]), nil
}

func VerifyCanonical(canonical, expectedHash string) (map[string]any, error) {
	if len(canonical) > maxCanonicalBytes || len(expectedHash) != 64 {
		return nil, errors.New("invalid canonical action or hash")
	}
	expected, err := hex.DecodeString(expectedHash)
	if err != nil || hex.EncodeToString(expected) != expectedHash {
		return nil, errors.New("invalid action hash")
	}
	sum := sha256.Sum256([]byte(canonical))
	if !hmac.Equal(sum[:], expected) {
		return nil, errors.New("action hash mismatch")
	}
	definition, err := Decode([]byte(canonical))
	if err != nil {
		return nil, err
	}
	reencoded, err := Canonical(definition)
	if err != nil || reencoded != canonical {
		return nil, errors.New("action definition is not canonical")
	}
	return definition, nil
}

func ValidateParameters(parameters map[string]any) error {
	if parameters == nil || len(parameters) != 0 {
		return errors.New("V1 action parameters must be an empty object")
	}
	return nil
}

func appendCanonical(buffer *bytes.Buffer, value any, depth int) error {
	if depth > maxDepth {
		return errors.New("JSON nesting exceeds 32 levels")
	}
	switch typed := value.(type) {
	case nil:
		buffer.WriteString("null")
	case bool:
		if typed {
			buffer.WriteString("true")
		} else {
			buffer.WriteString("false")
		}
	case string:
		if !utf8.ValidString(typed) {
			return errors.New("strings must be valid UTF-8")
		}
		appendString(buffer, typed)
	case json.Number:
		if typed.String() == "-0" || strings.ContainsAny(typed.String(), ".eE") {
			return errors.New("only canonical integers are allowed")
		}
		if _, err := strconv.ParseInt(typed.String(), 10, 64); err != nil {
			return errors.New("only signed 64-bit integers are allowed")
		}
		buffer.WriteString(typed.String())
	case []any:
		buffer.WriteByte('[')
		for index, item := range typed {
			if index > 0 {
				buffer.WriteByte(',')
			}
			if err := appendCanonical(buffer, item, depth+1); err != nil {
				return err
			}
		}
		buffer.WriteByte(']')
	case map[string]any:
		keys := make([]string, 0, len(typed))
		for key := range typed {
			if !utf8.ValidString(key) {
				return errors.New("object keys must be valid UTF-8")
			}
			keys = append(keys, key)
		}
		sort.Strings(keys)
		buffer.WriteByte('{')
		for index, key := range keys {
			if index > 0 {
				buffer.WriteByte(',')
			}
			appendString(buffer, key)
			buffer.WriteByte(':')
			if err := appendCanonical(buffer, typed[key], depth+1); err != nil {
				return err
			}
		}
		buffer.WriteByte('}')
	default:
		return fmt.Errorf("unsupported canonical JSON type %T", value)
	}
	return nil
}

func appendString(buffer *bytes.Buffer, value string) {
	encoded, _ := json.Marshal(value)
	encoded = bytes.ReplaceAll(encoded, []byte(`\u003c`), []byte("<"))
	encoded = bytes.ReplaceAll(encoded, []byte(`\u003e`), []byte(">"))
	encoded = bytes.ReplaceAll(encoded, []byte(`\u0026`), []byte("&"))
	encoded = bytes.ReplaceAll(encoded, []byte(`\u2028`), []byte("\xe2\x80\xa8"))
	encoded = bytes.ReplaceAll(encoded, []byte(`\u2029`), []byte("\xe2\x80\xa9"))
	buffer.Write(encoded)
}

func exactFields(object map[string]any, required, optional []string, path string) error {
	for _, name := range required {
		if _, ok := object[name]; !ok {
			return fmt.Errorf("%s has missing or unknown fields", path)
		}
	}
	for name := range object {
		known := false
		for _, candidate := range required {
			if name == candidate {
				known = true
				break
			}
		}
		if !known {
			for _, candidate := range optional {
				if name == candidate {
					known = true
					break
				}
			}
		}
		if !known {
			return fmt.Errorf("%s has missing or unknown fields", path)
		}
	}
	return nil
}
