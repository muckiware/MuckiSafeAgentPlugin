package api

import (
	"errors"
	"net/url"
)

// ValidateServerURL accepts `https://host[:port][/]` and, only together with
// allowInsecureHTTP, `http://host[:port][/]`. Credentials, paths, queries and
// fragments are rejected because the signed route path is absolute. Errors
// never echo the URL, which might carry credentials.
func ValidateServerURL(raw string, allowInsecureHTTP bool) error {
	parsed, err := url.Parse(raw)
	if err != nil {
		return errors.New("server.url is not a valid URL")
	}
	switch {
	case parsed.Scheme == "https":
	case parsed.Scheme == "http" && allowInsecureHTTP:
	case parsed.Scheme == "http":
		return errors.New("server.url uses http:// which requires server.allowInsecureHttp: true")
	default:
		return errors.New("server.url must use https:// (or http:// with server.allowInsecureHttp: true)")
	}
	if raw[:len(parsed.Scheme)] != parsed.Scheme {
		return errors.New("server.url scheme must be lowercase")
	}
	if parsed.User != nil {
		return errors.New("server.url must not contain credentials")
	}
	if parsed.Hostname() == "" || parsed.Opaque != "" {
		return errors.New("server.url must contain a host")
	}
	if (parsed.Path != "" && parsed.Path != "/") || parsed.RawQuery != "" || parsed.ForceQuery || parsed.Fragment != "" {
		return errors.New("server.url must not contain a path, query or fragment")
	}
	return nil
}
