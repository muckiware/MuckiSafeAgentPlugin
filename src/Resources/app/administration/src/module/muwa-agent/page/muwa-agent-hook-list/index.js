import template from './muwa-agent-hook-list.html.twig';

const { Mixin } = Shopware;
const { Criteria } = Shopware.Data;

/**
 * Repository hooks: which agent runs which action in which phase of a
 * Facility repository's backup. There are no cross-plugin associations, so
 * repositories, agents and actions are loaded once and joined by id here.
 */
export default {
    template,

    compatConfig: Shopware.compatConfig,

    inject: ['repositoryFactory', 'acl', 'muwaAgentApiService'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            hooks: null,
            facilityRepositories: [],
            hosts: [],
            actions: [],
            isLoading: false,
            editedHook: null,
            hookToDelete: null,
            isDeleting: false,
        };
    },

    computed: {
        hookRepository() {
            return this.repositoryFactory.create('muwa_agent_repository_hook');
        },

        canEdit() {
            return this.acl.can('muwa_agent.editor');
        },

        lookup() {
            const byId = (items) => Object.fromEntries(items.map((item) => [item.id, item]));

            return {
                repositories: byId(this.facilityRepositories),
                hosts: byId(this.hosts),
                actions: byId(this.actions),
            };
        },

        columns() {
            return [
                { property: 'facilityRepositoryId', label: this.$t('muwa-agent.hook.repositoryLabel'), primary: true, sortable: false },
                { property: 'phase', dataIndex: 'phase', label: this.$t('muwa-agent.hook.phaseLabel') },
                { property: 'agentHostId', label: this.$t('muwa-agent.hook.agentLabel'), sortable: false },
                { property: 'agentActionId', label: this.$t('muwa-agent.hook.actionLabel'), sortable: false },
                { property: 'timeoutSeconds', dataIndex: 'timeoutSeconds', label: this.$t('muwa-agent.hook.timeoutSecondsLabel') },
                { property: 'failOnError', dataIndex: 'failOnError', label: this.$t('muwa-agent.hook.failOnErrorLabel'), align: 'center' },
                { property: 'enabled', dataIndex: 'enabled', label: this.$t('muwa-agent.hook.enabledLabel'), align: 'center' },
            ];
        },
    },

    created() {
        this.loadAll();
    },

    methods: {
        async loadAll() {
            this.isLoading = true;
            try {
                const [repositories, hosts, actions] = await Promise.all([
                    this.searchAll('muwa_backup_repository', 'name'),
                    this.searchAll('muwa_agent_host', 'name'),
                    this.searchAll('muwa_agent_action', 'label'),
                ]);
                this.facilityRepositories = repositories;
                this.hosts = hosts;
                this.actions = actions;
                await this.getList();
            } finally {
                this.isLoading = false;
            }
        },

        async searchAll(entity, sortField) {
            const criteria = new Criteria(1, 500);
            criteria.addSorting(Criteria.sort(sortField, 'ASC'));
            const result = await this.repositoryFactory.create(entity).search(criteria, Shopware.Context.api);

            return Array.from(result);
        },

        async getList() {
            const criteria = new Criteria(1, 500);
            criteria.addSorting(Criteria.sort('phase', 'ASC'));
            this.hooks = await this.hookRepository.search(criteria, Shopware.Context.api);
        },

        nameOf(collection, id, field = 'name') {
            return this.lookup[collection][id]?.[field] ?? this.$t('muwa-agent.hook.unknownReference');
        },

        onCreate() {
            this.editedHook = {};
        },

        onEdit(hook) {
            this.editedHook = hook;
        },

        async onSaved() {
            this.editedHook = null;
            await this.loadAll();
        },

        async onConfirmDelete() {
            this.isDeleting = true;
            try {
                await this.muwaAgentApiService.deleteHook(this.hookToDelete.id);
                this.createNotificationSuccess({
                    title: this.$t('global.default.success'),
                    message: this.$t('muwa-agent.hook.deleteSuccess'),
                });
            } catch (exception) {
                const code = exception?.response?.data?.error;
                const key = `muwa-agent.errors.${code}`;
                this.createNotificationError({
                    title: this.$t('global.default.error'),
                    message: code && this.$te(key) ? this.$t(key) : this.$t('muwa-agent.errors.operation_failed'),
                });
            } finally {
                this.isDeleting = false;
                this.hookToDelete = null;
            }
            await this.getList();
        },
    },
};
