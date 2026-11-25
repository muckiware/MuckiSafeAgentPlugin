import template from './muwa-agent-run-list.html.twig';
import { statusVariant, formatDuration } from '../../helper/status';

const { Criteria } = Shopware.Data;

/**
 * Read-only list of backup runs, newest first. A run opens a modal with its
 * jobs; failure tails there require the executor role.
 */
export default {
    template,

    compatConfig: Shopware.compatConfig,

    inject: ['repositoryFactory'],

    data() {
        return {
            runs: null,
            repositoryNames: {},
            isLoading: false,
            selectedRun: null,
        };
    },

    computed: {
        runRepository() {
            return this.repositoryFactory.create('muwa_agent_backup_run');
        },

        dateFilter() {
            return Shopware.Filter.getByName('date');
        },

        columns() {
            return [
                { property: 'startedAt', dataIndex: 'startedAt', label: this.$t('muwa-agent.run.startedAtLabel'), primary: true },
                { property: 'facilityRepositoryId', label: this.$t('muwa-agent.hook.repositoryLabel'), sortable: false },
                { property: 'status', dataIndex: 'status', label: this.$t('muwa-agent.run.statusLabel') },
                { property: 'duration', label: this.$t('muwa-agent.run.durationLabel'), sortable: false },
                { property: 'primaryErrorCode', dataIndex: 'primaryErrorCode', label: this.$t('muwa-agent.run.primaryErrorLabel') },
            ];
        },
    },

    created() {
        this.loadRepositoryNames();
        this.getList();
    },

    methods: {
        async loadRepositoryNames() {
            const result = await this.repositoryFactory.create('muwa_backup_repository')
                .search(new Criteria(1, 500), Shopware.Context.api);
            this.repositoryNames = Object.fromEntries(Array.from(result).map((item) => [item.id, item.name]));
        },

        getList() {
            this.isLoading = true;
            const criteria = new Criteria(1, 25);
            criteria.addSorting(Criteria.sort('startedAt', 'DESC'));

            return this.runRepository.search(criteria, Shopware.Context.api).then((result) => {
                this.runs = result;
            }).finally(() => {
                this.isLoading = false;
            });
        },

        repositoryName(run) {
            return this.repositoryNames[run.facilityRepositoryId] ?? this.$t('muwa-agent.hook.unknownReference');
        },

        statusVariant,

        formatDuration,

        formatDate(value) {
            return value ? this.dateFilter(value, { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '–';
        },
    },
};
