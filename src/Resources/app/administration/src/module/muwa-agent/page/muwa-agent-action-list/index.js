import template from './muwa-agent-action-list.html.twig';

const { Criteria } = Shopware.Data;

/**
 * Actions: technical name, status, idempotency class, timeouts and the
 * server-derived required capabilities. Editing happens on the detail page.
 */
export default {
    template,

    compatConfig: Shopware.compatConfig,

    inject: ['repositoryFactory', 'acl'],

    data() {
        return {
            actions: null,
            isLoading: false,
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('muwa_agent_action');
        },

        canEdit() {
            return this.acl.can('muwa_agent.editor');
        },

        columns() {
            return [
                {
                    property: 'label',
                    dataIndex: 'label',
                    label: this.$t('muwa-agent.action.labelLabel'),
                    allowResize: true,
                    primary: true,
                    routerLink: 'muwa.agent.actionDetail',
                }, {
                    property: 'technicalName',
                    dataIndex: 'technicalName',
                    label: this.$t('muwa-agent.action.technicalNameLabel'),
                    allowResize: true,
                }, {
                    property: 'enabled',
                    dataIndex: 'enabled',
                    label: this.$t('muwa-agent.action.enabledLabel'),
                    allowResize: true,
                    align: 'center',
                }, {
                    property: 'idempotencyClass',
                    dataIndex: 'idempotencyClass',
                    label: this.$t('muwa-agent.action.idempotencyClassLabel'),
                    allowResize: true,
                }, {
                    property: 'maxTimeoutSeconds',
                    dataIndex: 'maxTimeoutSeconds',
                    label: this.$t('muwa-agent.action.maxTimeoutSecondsLabel'),
                    allowResize: true,
                }, {
                    property: 'requiredCapabilities',
                    label: this.$t('muwa-agent.action.requiredCapabilitiesLabel'),
                    allowResize: true,
                    sortable: false,
                },
            ];
        },
    },

    created() {
        this.getList();
    },

    methods: {
        getList() {
            this.isLoading = true;

            const criteria = new Criteria(1, 100);
            criteria.addSorting(Criteria.sort('label', 'ASC'));

            return this.repository.search(criteria, Shopware.Context.api).then((result) => {
                this.actions = result;
            }).finally(() => {
                this.isLoading = false;
            });
        },

        formatCapabilities(action) {
            const capabilities = Array.isArray(action.requiredCapabilities) ? action.requiredCapabilities : [];

            return capabilities.length > 0 ? capabilities.join(', ') : '–';
        },
    },
};
