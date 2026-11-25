import './acl';
import MuwaAgentApiService from './service/muwa-agent-api.service';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Service().register('muwaAgentApiService', (container) => {
    const initContainer = Shopware.Application.getContainer('init');

    return new MuwaAgentApiService(initContainer.httpClient, container.loginService);
});

Shopware.Component.register('muwa-agent-index', () => import('./page/muwa-agent-index'));
Shopware.Component.register('muwa-agent-host-list', () => import('./page/muwa-agent-host-list'));
Shopware.Component.register('muwa-agent-action-list', () => import('./page/muwa-agent-action-list'));
Shopware.Component.register('muwa-agent-action-detail', () => import('./page/muwa-agent-action-detail'));
Shopware.Component.register('muwa-agent-hook-list', () => import('./page/muwa-agent-hook-list'));
Shopware.Component.register('muwa-agent-hook-modal', () => import('./component/muwa-agent-hook-modal'));
Shopware.Component.register('muwa-agent-run-list', () => import('./page/muwa-agent-run-list'));
Shopware.Component.register('muwa-agent-run-modal', () => import('./component/muwa-agent-run-modal'));
Shopware.Component.register('muwa-agent-provision-modal', () => import('./component/muwa-agent-provision-modal'));
Shopware.Component.register('muwa-agent-secret-modal', () => import('./component/muwa-agent-secret-modal'));

Shopware.Module.register('muwa-agent', {
    type: 'plugin',
    name: 'muwaAgent',
    title: 'muwa-agent.general.mainMenuLabel',
    description: 'muwa-agent.general.description',
    color: '#c04d01',
    icon: 'regular-server',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB,
    },

    routes: {
        index: {
            component: 'muwa-agent-index',
            path: 'index',
            redirect: {
                name: 'muwa.agent.index.hosts',
            },
            meta: {
                parentPath: 'sw.settings.index.plugins',
                privilege: 'muwa_agent.viewer',
            },
            children: {
                hosts: {
                    component: 'muwa-agent-host-list',
                    path: 'hosts',
                    meta: {
                        parentPath: 'sw.settings.index.plugins',
                        privilege: 'muwa_agent.viewer',
                    },
                },
                actions: {
                    component: 'muwa-agent-action-list',
                    path: 'actions',
                    meta: {
                        parentPath: 'sw.settings.index.plugins',
                        privilege: 'muwa_agent.viewer',
                    },
                },
                hooks: {
                    component: 'muwa-agent-hook-list',
                    path: 'hooks',
                    meta: {
                        parentPath: 'sw.settings.index.plugins',
                        privilege: 'muwa_agent.viewer',
                    },
                },
                runs: {
                    component: 'muwa-agent-run-list',
                    path: 'runs',
                    meta: {
                        parentPath: 'sw.settings.index.plugins',
                        privilege: 'muwa_agent.viewer',
                    },
                },
            },
        },
        actionCreate: {
            component: 'muwa-agent-action-detail',
            path: 'action/create',
            meta: {
                parentPath: 'muwa.agent.index.actions',
                privilege: 'muwa_agent.editor',
            },
        },
        actionDetail: {
            component: 'muwa-agent-action-detail',
            path: 'action/detail/:id',
            props: {
                default(route) {
                    return { actionId: route.params.id };
                },
            },
            meta: {
                parentPath: 'muwa.agent.index.actions',
                privilege: 'muwa_agent.viewer',
            },
        },
    },

    settingsItem: [
        {
            name: 'muwa-agent-index',
            to: 'muwa.agent.index',
            group: 'plugins',
            icon: 'regular-server',
            backgroundEnabled: true,
            privilege: 'muwa_agent.viewer',
            label: 'muwa-agent.general.mainMenuLabel',
        },
    ],
});
