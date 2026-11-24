package api

import (
	"bytes"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"reflect"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"testing"
	"unicode/utf8"
)

// schemaDir holds the V1 DTO schemas shared with the PHP control plane.
var schemaDir = filepath.Join("..", "..", "..", "resources", "schema", "protocol", "v1")

type dtoFixture struct {
	SupportedKeywords []string `json:"supportedKeywords"`
	Cases             []struct {
		Schema    string `json:"schema"`
		Name      string `json:"name"`
		Valid     bool   `json:"valid"`
		Violation string `json:"violation"`
		Document  any    `json:"document"`
	} `json:"cases"`
}

// decodeNumbers keeps JSON numbers as json.Number so that 1 and 1.0 stay distinguishable.
func decodeNumbers(t *testing.T, raw []byte, target any) {
	t.Helper()
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()
	if err := decoder.Decode(target); err != nil {
		t.Fatalf("decode: %v", err)
	}
}

func testDTOs(t *testing.T) {
	raw, err := os.ReadFile(filepath.Join(fixtureDir, "dtos-v1.json"))
	if err != nil {
		t.Fatal(err)
	}
	var fixture dtoFixture
	decodeNumbers(t, raw, &fixture)
	validator := schemaValidator{supported: map[string]bool{}}
	for _, keyword := range fixture.SupportedKeywords {
		validator.supported[keyword] = true
	}
	coverage := map[string]map[bool]bool{}
	for _, c := range fixture.Cases {
		t.Run(c.Schema+": "+c.Name, func(t *testing.T) {
			violation, err := validator.firstViolation(loadSchema(t, c.Schema), c.Document)
			if err != nil {
				t.Fatal(err)
			}
			want := ""
			if !c.Valid {
				want = c.Violation
			}
			if violation != want {
				t.Fatalf("violation %q, want %q", violation, want)
			}
		})
		if coverage[c.Schema] == nil {
			coverage[c.Schema] = map[bool]bool{}
		}
		coverage[c.Schema][c.Valid] = true
	}
	files, _ := filepath.Glob(filepath.Join(schemaDir, "*.schema.json"))
	if len(files) != 11 {
		t.Fatalf("expected 11 schemas, found %d", len(files))
	}
	for _, file := range files {
		name := strings.TrimSuffix(filepath.Base(file), ".schema.json")
		if !coverage[name][true] || !coverage[name][false] {
			t.Errorf("schema %s lacks valid or invalid documents", name)
		}
	}
}

func loadSchema(t *testing.T, name string) map[string]any {
	t.Helper()
	raw, err := os.ReadFile(filepath.Join(schemaDir, name+".schema.json"))
	if err != nil {
		t.Fatal(err)
	}
	var schema map[string]any
	decodeNumbers(t, raw, &schema)
	return schema
}

// schemaValidator implements exactly the keyword subset declared in
// dtos-v1.json, like tests/Support/ProtocolSchemaValidator.php on the PHP side.
type schemaValidator struct {
	supported map[string]bool
}

func (v schemaValidator) firstViolation(schema map[string]any, value any) (string, error) {
	for keyword := range schema {
		if !v.supported[keyword] {
			return "", fmt.Errorf("unsupported schema keyword %s", keyword)
		}
	}
	if typ, ok := schema["type"]; ok {
		matches, err := matchesType(typ, value)
		if err != nil || !matches {
			return "type", err
		}
	}
	if constant, ok := schema["const"]; ok && !reflect.DeepEqual(constant, value) {
		return "const", nil
	}
	if enum, ok := schema["enum"].([]any); ok && !inEnum(enum, value) {
		return "enum", nil
	}
	var baseViolation string
	var err error
	switch typed := value.(type) {
	case string:
		baseViolation = stringViolation(schema, typed)
	case json.Number:
		baseViolation, err = integerViolation(schema, typed)
	case []any:
		baseViolation, err = v.arrayViolation(schema, typed)
	case map[string]any:
		baseViolation, err = v.objectViolation(schema, typed)
	}
	if err != nil || baseViolation != "" {
		return baseViolation, err
	}
	if alternatives, ok := schema["oneOf"].([]any); ok {
		matches := 0
		for _, alternative := range alternatives {
			subschema, ok := alternative.(map[string]any)
			if !ok {
				return "", fmt.Errorf("oneOf member is not an object")
			}
			violation, subErr := v.firstViolation(subschema, value)
			if subErr != nil {
				return "", subErr
			}
			if violation == "" {
				matches++
			}
		}
		if matches != 1 {
			return "oneOf", nil
		}
	}
	if negated, ok := schema["not"]; ok {
		subschema, ok := negated.(map[string]any)
		if !ok {
			return "", fmt.Errorf("not member is not an object")
		}
		violation, subErr := v.firstViolation(subschema, value)
		if subErr != nil {
			return "", subErr
		}
		if violation == "" {
			return "not", nil
		}
	}
	return "", nil
}

func matchesType(typ any, value any) (bool, error) {
	candidates := []any{typ}
	if list, ok := typ.([]any); ok {
		candidates = list
	}
	for _, candidate := range candidates {
		var matches bool
		switch candidate {
		case "object":
			_, matches = value.(map[string]any)
		case "array":
			_, matches = value.([]any)
		case "string":
			_, matches = value.(string)
		case "integer":
			number, ok := value.(json.Number)
			matches = ok && !strings.ContainsAny(number.String(), ".eE")
		case "boolean":
			_, matches = value.(bool)
		case "null":
			matches = value == nil
		default:
			return false, fmt.Errorf("unsupported schema type %v", candidate)
		}
		if matches {
			return true, nil
		}
	}
	return false, nil
}

func inEnum(enum []any, value any) bool {
	for _, candidate := range enum {
		if reflect.DeepEqual(candidate, value) {
			return true
		}
	}
	return false
}

func intOf(value any) int64 {
	number, _ := value.(json.Number)
	parsed, _ := strconv.ParseInt(number.String(), 10, 64)
	return parsed
}

func stringViolation(schema map[string]any, value string) string {
	length := int64(utf8.RuneCountInString(value))
	if limit, ok := schema["minLength"]; ok && length < intOf(limit) {
		return "minLength"
	}
	if limit, ok := schema["maxLength"]; ok && length > intOf(limit) {
		return "maxLength"
	}
	if pattern, ok := schema["pattern"].(string); ok && !regexp.MustCompile(pattern).MatchString(value) {
		return "pattern"
	}
	return ""
}

func integerViolation(schema map[string]any, value json.Number) (string, error) {
	parsed, err := strconv.ParseInt(value.String(), 10, 64)
	if err != nil {
		return "", err
	}
	if limit, ok := schema["minimum"]; ok && parsed < intOf(limit) {
		return "minimum", nil
	}
	if limit, ok := schema["maximum"]; ok && parsed > intOf(limit) {
		return "maximum", nil
	}
	return "", nil
}

func (v schemaValidator) arrayViolation(schema map[string]any, value []any) (string, error) {
	count := int64(len(value))
	if limit, ok := schema["minItems"]; ok && count < intOf(limit) {
		return "minItems", nil
	}
	if limit, ok := schema["maxItems"]; ok && count > intOf(limit) {
		return "maxItems", nil
	}
	if schema["uniqueItems"] == true {
		seen := map[string]bool{}
		for _, item := range value {
			encoded, _ := json.Marshal(item)
			if seen[string(encoded)] {
				return "uniqueItems", nil
			}
			seen[string(encoded)] = true
		}
	}
	items, ok := schema["items"].(map[string]any)
	if !ok {
		return "", nil
	}
	for _, item := range value {
		if violation, err := v.firstViolation(items, item); violation != "" || err != nil {
			return violation, err
		}
	}
	return "", nil
}

func (v schemaValidator) objectViolation(schema map[string]any, value map[string]any) (string, error) {
	properties, _ := schema["properties"].(map[string]any)
	required, _ := schema["required"].([]any)
	for _, name := range required {
		if _, ok := value[name.(string)]; !ok {
			return "required", nil
		}
	}
	names := make([]string, 0, len(value))
	for name := range value {
		names = append(names, name)
	}
	sort.Strings(names)
	for _, name := range names {
		property, declared := properties[name].(map[string]any)
		if !declared {
			if schema["additionalProperties"] == false {
				return "additionalProperties", nil
			}
			continue
		}
		if violation, err := v.firstViolation(property, value[name]); violation != "" || err != nil {
			return violation, err
		}
	}
	return "", nil
}
