// Package api implements the MUWA-HMAC-V1 wire protocol of the MuckiSafe agent.
//
// Routes, body limits, error codes and signatures mirror the PHP control plane
// (src/Service/Auth) and are pinned by the shared fixtures under
// tests/Fixtures/protocol of the repository root.
package api

import (
	"regexp"
)

// RoutePrefix is the versioned prefix of all agent routes.
const RoutePrefix = "/api/_action/muwa/agent/v1"

// TransportHardCapBytes is the absolute upstream transport cap above every route limit.
const TransportHardCapBytes = 270336

// TimestampWindowSeconds is the accepted clock skew for request timestamps.
const TimestampWindowSeconds = 60

// Route names of the V1 wire contract.
const (
	RouteHostHeartbeat = "api.action.muwa.agent.v1.host_heartbeat"
	RouteJobClaim      = "api.action.muwa.agent.v1.job_claim"
	RouteJobStart      = "api.action.muwa.agent.v1.job_start"
	RouteJobHeartbeat  = "api.action.muwa.agent.v1.job_heartbeat"
	RouteJobComplete   = "api.action.muwa.agent.v1.job_complete"
)

// Route describes one agent route. Path uses the literal {jobId} placeholder.
type Route struct {
	Name           string
	Method         string
	Path           string
	BodyLimitBytes int
}

var routes = []Route{
	{RouteHostHeartbeat, "POST", RoutePrefix + "/heartbeat", 16384},
	{RouteJobClaim, "POST", RoutePrefix + "/jobs/claim", 1024},
	{RouteJobStart, "POST", RoutePrefix + "/jobs/{jobId}/start", 4096},
	{RouteJobHeartbeat, "POST", RoutePrefix + "/jobs/{jobId}/heartbeat", 4096},
	{RouteJobComplete, "POST", RoutePrefix + "/jobs/{jobId}/complete", 262144},
}

// uuidPattern is a lowercase RFC 4122 UUID (versions 1-8, RFC variant).
const uuidPattern = `[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}`

var jobRoutePattern = regexp.MustCompile(`^` + regexp.QuoteMeta(RoutePrefix) + `/jobs/` + uuidPattern + `/(start|heartbeat|complete)$`)

var jobActionRoutes = map[string]string{
	"start":     RouteJobStart,
	"heartbeat": RouteJobHeartbeat,
	"complete":  RouteJobComplete,
}

// Routes returns a copy of the route table in wire-contract order.
func Routes() []Route {
	return append([]Route(nil), routes...)
}

// MatchRoute resolves the route of an exact, already canonical path. Query,
// fragment, percent encoding, backslash, dot segments, double or trailing
// slashes and any other spelling are rejected, never normalized.
func MatchRoute(path string) (Route, error) {
	switch path {
	case routes[0].Path:
		return routes[0], nil
	case routes[1].Path:
		return routes[1], nil
	}
	match := jobRoutePattern.FindStringSubmatch(path)
	if match == nil {
		return Route{}, ErrInvalidPath
	}
	return routeByName(jobActionRoutes[match[1]])
}

// CheckBodyLimit rejects a raw body length above the route limit.
func CheckBodyLimit(routeName string, length int) error {
	route, err := routeByName(routeName)
	if err != nil {
		return err
	}
	if length > route.BodyLimitBytes {
		return ErrBodyTooLarge
	}
	return nil
}

func routeByName(name string) (Route, error) {
	for _, route := range routes {
		if route.Name == name {
			return route, nil
		}
	}
	return Route{}, ErrInvalidPath
}
