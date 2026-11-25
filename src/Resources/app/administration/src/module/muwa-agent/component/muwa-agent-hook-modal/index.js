import template from './muwa-agent-hook-modal.html.twig';
import './muwa-agent-hook-modal.scss';

const PHASE_BUDGETS = {
    pre: { default: 600, max: 1800 },
    post: { default: 600, max: 1800 },
    finally: { default: 180, max: 600 },
};
const MIN_BUDGET_SECONDS = 45;

/**
 * Assigns an agent action to one phase of a Facility repository's backup.
 * Repository and phase identify the hook and are fixed once it exists. The
 * hints (legacy hooks, missing capabilities, budget bounds) mirror the server
 * checks in RepositoryHookConfigurator, which remain authoritative.
 */
export default {
    template,

    compatConfig: Shopware.compatConfig,

    inject: ['muwaAgentApiService'],

    mixins: [
        Shopware.Mixin.getByName('notification'),
    ],

    emits: ['saved', 'close'],

    props: {
        hook: {
            type: Object,
            required: true,
        },
        facilityRepositories: {
            type: Array,
            required: true,
        },
        hosts: {
            type: Array,
            required: true,
        },
        actions: {
            type: Array,
            required: true,
        },
    },

    data() {
        return {
            form: {
                facilityRepositoryId: this.hook.facilityRepositoryId ?? null,
                phase: this.hook.phase ?? 'pre',
                agentHostId: this.hook.agentHostId ?? null,
                agentActionId: this.hook.agentActionId ?? null,
                timeoutSeconds: this.hook.timeoutSeconds ?? PHASE_BUDGETS.pre.default,
                failOnError: this.hook.failOnError ?? true,
                enabled: this.hook.enabled ?? true,
            },
            isSaving: false,
            serverConflictFields: [],
        };
    },

    computed: {
        isNew() {
            return !this.hook.id;
        },

        repositoryOptions() {
            return this.facilityRepositories.map((repository) => ({ value: repository.id, label: repository.name }));
        },

        phaseOptions() {
            return Object.keys(PHASE_BUDGETS).map((phase) => ({ value: phase, label: this.$t(`muwa-agent.hook.phases.${phase}`) }));
        },

        hostOptions() {
            return this.hosts
                .filter((host) => (host.enabled && !host.revokedAt) || host.id === this.form.agentHostId)
                .map((host) => ({
                    value: host.id,
                    label: host.revokedAt ? `${host.name} (${this.$t('muwa-agent.host.status.revoked')})` : host.name,
                }));
        },

        actionOptions() {
            return this.actions
                .filter((action) => action.enabled || action.id === this.form.agentActionId)
                .map((action) => ({ value: action.id, label: `${action.label} (${action.technicalName})` }));
        },

        selectedHost() {
            return this.hosts.find((host) => host.id === this.form.agentHostId) ?? null;
        },

        selectedAction() {
            return this.actions.find((action) => action.id === this.form.agentActionId) ?? null;
        },

        legacyHookFields() {
            if (this.serverConflictFields.length > 0) {
                return this.serverConflictFields;
            }
            const repository = this.facilityRepositories.find((item) => item.id === this.form.facilityRepositoryId);
            if (!repository) {
                return [];
            }

            return ['preBackupCommandType', 'postBackupCommandType']
                .filter((field) => repository[field] && repository[field] !== 'none');
        },

        missingCapabilities() {
            if (!this.selectedHost || !this.selectedAction) {
                return [];
            }
            const available = Array.isArray(this.selectedHost.capabilities) ? this.selectedHost.capabilities : [];

            return (this.selectedAction.requiredCapabilities || []).filter((capability) => !available.includes(capability));
        },

        maxBudget() {
            const phaseMax = PHASE_BUDGETS[this.form.phase].max;

            return this.selectedAction ? Math.min(phaseMax, this.selectedAction.maxTimeoutSeconds) : phaseMax;
        },

        minBudget() {
            return MIN_BUDGET_SECONDS;
        },

        isComplete() {
            return Boolean(this.form.facilityRepositoryId && this.form.agentHostId && this.form.agentActionId)
                && Number.isInteger(this.form.timeoutSeconds)
                && this.form.timeoutSeconds >= MIN_BUDGET_SECONDS
                && this.form.timeoutSeconds <= this.maxBudget;
        },
    },

    watch: {
        'form.phase'(phase) {
            if (this.isNew) {
                this.form.timeoutSeconds = Math.min(PHASE_BUDGETS[phase].default, this.maxBudget);
            }
        },

        'form.facilityRepositoryId'() {
            this.serverConflictFields = [];
        },

        maxBudget(max) {
            if (this.form.timeoutSeconds > max) {
                this.form.timeoutSeconds = max;
            }
        },
    },

    methods: {
        legacyFieldLabel(field) {
            return this.$t(`muwa-agent.hook.legacyFields.${field}`);
        },

        async onSave() {
            this.isSaving = true;
            try {
                await this.muwaAgentApiService.saveHook({ ...this.form, parameters: {} });
                this.createNotificationSuccess({
                    title: this.$t('global.default.success'),
                    message: this.$t('muwa-agent.hook.saveSuccess'),
                });
                this.$emit('saved');
            } catch (exception) {
                this.handleError(exception?.response?.data || {});
            } finally {
                this.isSaving = false;
            }
        },

        handleError(data) {
            if (data.error === 'legacy_hook_conflict' && Array.isArray(data.fields)) {
                this.serverConflictFields = data.fields;
            }
            const key = `muwa-agent.errors.${data.error}`;
            this.createNotificationError({
                title: this.$t('global.default.error'),
                message: data.error && this.$te(key) ? this.$t(key) : this.$t('muwa-agent.errors.operation_failed'),
            });
        },
    },
};
