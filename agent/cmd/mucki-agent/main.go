// Command mucki-agent is the MuckiSafe host agent.
package main

import (
	"os"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/cli"
)

func main() {
	os.Exit(cli.Run(os.Args[1:], os.Stdout, os.Stderr))
}
