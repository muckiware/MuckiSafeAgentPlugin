import template from './muwa-agent-run-modal.html.twig';
import './muwa-agent-run-modal.scss';
import { statusVariant, formatDuration } from '../../helper/status';

const { Criteria } = Shopware.Data;

const FAILED_STATUSES = ['failed', 'timed_out', 'unknown', 'expired'];

/**
 * Jobs of one backup run (read-only). Failure tails are fetched on demand
 * through the executor-only output route and kept only while the modal is
 * open; the generic API never returns them.
 */
export default {
    template,

    compatConfig: Shopware.compatConfig,

    inject: ['repositoryFactory', 'acl', 'muwaAgentApiService'],

    emits: ['close'],

    props: {
        run: {
            type: Object,
            required: true,
        },
        repositoryName: {
            type: String,
            required: true,
        },
    },

    data() {
        return {
            jobs: [],
            hostNames: {},
            actionLabels: {},
            isLoading: false,
            outputs: {},
            loadingOutput: null,
        };
    },

    computed: {
        canReadOutput() {
            return this.acl.can('muwa_agent.executor');
        },

        dateFilter() {
            return Shopware.Filter.getByName('date');
        },
    },

    created() {
        this.loadJobs();
    },

    methods: {
        async loadJobs() {
            this.isLoading = true;
            try {
                const criteria = new Criteria(1, 100);
                criteria.addFilter(Criteria.equals('backupRunId', this.run.id));
                criteria.addSorting(Criteria.sort('createdAt', 'ASC'));
                const [jobs, hosts, actions] = await Promise.all([
                    this.repositoryFactory.create('muwa_agent_job').search(criteria, Shopware.Context.api),
                    this.repositoryFactory.create('muwa_agent_host').search(new Criteria(1, 500), Shopware.Context.api),
                    this.repositoryFactory.create('muwa_agent_action').search(new Criteria(1, 500), Shopware.Context.api),
                ]);
                this.jobs = Array.from(jobs);
                this.hostNames = Object.fromEntries(Array.from(hosts).map((host) => [host.id, host.name]));
                this.actionLabels = Object.fromEntries(Array.from(actions).map((action) => [action.id, action.label]));
            } finally {
                this.isLoading = false;
            }
        },

        isFailed(job) {
            return FAILED_STATUSES.includes(job.status);
        },

        async onShowOutput(job) {
            this.loadingOutput = job.id;
            try {
                this.outputs = { ...this.outputs, [job.id]: await this.muwaAgentApiService.jobOutput(job.id) };
            } catch {
                this.outputs = { ...this.outputs, [job.id]: { error: true } };
            } finally {
                this.loadingOutput = null;
            }
        },

        statusVariant,

        formatDuration,

        formatDate(value) {
            return value ? this.dateFilter(value, { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '–';
        },
    },
};
