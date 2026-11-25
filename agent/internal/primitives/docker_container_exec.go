package primitives

import (
	"context"
	"time"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/executor"
)

// containerExec runs the fixed argv of a command alias in its fixed
// container through `/usr/bin/docker exec`. The action supplies only the two
// aliases and an empty V1 arguments object; neither the container, the
// program nor any argument comes from the control plane.
type containerExec struct {
	docker string
}

func (c *containerExec) Run(ctx context.Context, step executor.StepContext) error {
	command, container, err := resolveCommand(step, "commandAlias")
	if err != nil {
		return err
	}
	if alias, _ := step.Step["containerAlias"].(string); alias != command.ContainerAlias {
		return failure(codePolicy, "command %s is bound to container %s, not %s", step.Step["commandAlias"], command.ContainerAlias, alias)
	}
	status, err := runBounded(ctx, step, dockerExec(c.docker, container, command), time.Duration(command.TimeoutSeconds)*time.Second, codeCommand)
	if err != nil {
		return err
	}
	if status.ExitCode != 0 {
		return failure(codeCommand, "command %s in container %s exited with %d", step.Step["commandAlias"], container.Name, status.ExitCode)
	}
	return nil
}

// resolveCommand resolves the command alias held by field and the container
// it is bound to.
func resolveCommand(step executor.StepContext, field string) (commandEntry, containerEntry, error) {
	command, alias, err := resolve[commandEntry](step, field, sectionCommands)
	if err != nil {
		return commandEntry{}, containerEntry{}, err
	}
	container, err := lookup[containerEntry](step, sectionContainers, command.ContainerAlias, "command "+alias)
	return command, container, err
}

// dockerExec never allocates a TTY or attaches stdin; argv items after the
// container name are not parsed as docker options.
func dockerExec(docker string, container containerEntry, command commandEntry) executor.Command {
	args := append([]string{"exec", container.Name}, command.Argv...)
	return executor.Command{Path: docker, Args: args}
}
