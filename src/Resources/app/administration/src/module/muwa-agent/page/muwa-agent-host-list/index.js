import template from './muwa-agent-host-list.html.twig';

const { Mixin } = Shopware;
const { Criteria } = Shopware.Data;

/**
 * Agent hosts: status, version, last contact and capabilities, plus
 * provisioning, secret rotation and revocation. Secrets returned by the
 * server are handed to the secret modal and dropped when it closes.
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
            hosts: null,
            isLoading: false,
            showProvisionModal: false,
            confirmation: null,
            isConfirming: false,
            issuedSecret: null,
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('muwa_agent_host');
        },

        dateFilter() {
            return Shopware.Filter.getByName('date');
        },

        canProvision() {
            return this.acl.can('muwa_agent.provisioner');
        },

        columns() {
            return [
                {
                    property: 'name',
                    dataIndex: 'name',
                    label: this.$t('muwa-agent.host.nameLabel'),
                    allowResize: true,
                    primary: true,
                }, {
                    property: 'status',
                    label: this.$t('muwa-agent.host.statusLabel'),
                    allowResize: true,
                    sortable: false,
                }, {
                    property: 'version',
                    dataIndex: 'version',
                    label: this.$t('muwa-agent.host.versionLabel'),
                    allowResize: true,
                }, {
                    property: 'lastSeenAt',
                    dataIndex: 'lastSeenAt',
                    label: this.$t('muwa-agent.host.lastSeenAtLabel'),
                    allowResize: true,
                }, {
                    property: 'capabilities',
                    label: this.$t('muwa-agent.host.capabilitiesLabel'),
                    allowResize: true,
                    sortable: false,
                }, {
                    property: 'keyId',
                    dataIndex: 'keyId',
                    label: this.$t('muwa-agent.host.keyIdLabel'),
                    allowResize: true,
                    visible: false,
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
            criteria.addSorting(Criteria.sort('name', 'ASC'));

            return this.repository.search(criteria, Shopware.Context.api).then((result) => {
                this.hosts = result;
            }).finally(() => {
                this.isLoading = false;
            });
        },

        hostStatus(host) {
            if (host.revokedAt) {
                return 'revoked';
            }
            if (!host.enabled) {
                return 'disabled';
            }

            return host.lastSeenAt ? 'active' : 'pending';
        },

        statusVariant(host) {
            return {
                revoked: 'danger',
                disabled: 'neutral',
                pending: 'warning',
                active: 'success',
            }[this.hostStatus(host)];
        },

        formatCapabilities(host) {
            const capabilities = Array.isArray(host.capabilities) ? host.capabilities : [];

            return capabilities.length > 0 ? capabilities.join(', ') : '–';
        },

        onProvisioned(result) {
            this.showProvisionModal = false;
            this.issuedSecret = { ...result, mode: 'provision' };
            this.getList();
        },

        onRequestConfirmation(type, host) {
            this.confirmation = { type, host };
        },

        onCancelConfirmation() {
            this.confirmation = null;
        },

        async onConfirm() {
            const { type, host } = this.confirmation;
            this.isConfirming = true;

            try {
                if (type === 'rotate') {
                    const result = await this.muwaAgentApiService.rotate(host.id);
                    this.issuedSecret = { ...result, keyId: host.keyId, name: host.name, mode: 'rotate' };
                } else {
                    await this.muwaAgentApiService.revoke(host.id);
                    this.createNotificationSuccess({
                        title: this.$t('global.default.success'),
                        message: this.$t('muwa-agent.host.revokeSuccess', { name: host.name }),
                    });
                }
            } catch (exception) {
                this.notifyApiError(exception);
            } finally {
                this.isConfirming = false;
                this.confirmation = null;
            }

            await this.getList();
        },

        onCloseSecret() {
            this.issuedSecret = null;
        },

        notifyApiError(exception) {
            const code = exception?.response?.data?.error;
            const key = `muwa-agent.errors.${code}`;
            const message = code && this.$te(key) ? this.$t(key) : this.$t('muwa-agent.errors.operation_failed');

            this.createNotificationError({
                title: this.$t('global.default.error'),
                message,
            });
        },
    },
};
