/**
 * ACL roles of the MuckiSafe Agent module. The role keys (muwa_agent.viewer,
 * ...) are checked by the server-side admin routes; the privileges listed per
 * role are the generic DAL read privileges and the dedicated route privileges
 * the role bundles. Writes on agent entities are never granted through the
 * generic DAL; the server rejects them independently of these roles.
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
    },
});
