/**
 * ACL roles of the MuckiSafe Agent module. The role keys (muwa_agent.viewer,
 * ...) are checked by the server-side admin routes; the privileges listed per
 * role are the generic DAL read privileges and the dedicated route privileges
 * the role bundles. Writes on agent entities are never granted through the
 * generic DAL; the server rejects them independently of these roles.
 *
 * The permission grid only offers viewer/editor/creator/deleter columns, so
 * provisioner and executor are additional permissions of the same key.
 */
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'settings',
    key: 'muwa_agent',
    roles: {
        viewer: {
            privileges: [
                'muwa_agent_host:read',
                'muwa_agent_action:read',
                'muwa_agent_repository_hook:read',
                'muwa_agent_backup_run:read',
                'muwa_agent_job:read',
                // Facility repositories are read through the Facility entity (name and legacy hook types).
                'muwa_backup_repository:read',
            ],
            dependencies: [],
        },
        editor: {
            privileges: [],
            dependencies: [
                'muwa_agent.viewer',
            ],
        },
    },
});

Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'additional_permissions',
    parent: null,
    key: 'muwa_agent',
    roles: {
        provisioner: {
            privileges: [
                'muwa_agent.provision',
                'muwa_agent.rotate',
                'muwa_agent.revoke',
                'muwa_agent.master_key_reencrypt',
            ],
            dependencies: [
                'muwa_agent.viewer',
            ],
        },
        // Reads stdout/stderr failure tails through the dedicated output route.
        executor: {
            privileges: [],
            dependencies: [
                'muwa_agent.viewer',
            ],
        },
    },
});
