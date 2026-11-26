import template from './muwa-agent-rollback.html.twig';
import './muwa-agent-rollback.scss';
import { statusVariant } from '../../helper/status';

const { Mixin } = Shopware;

export const OPEN_JOB_STATES = ['queued', 'claimed', 'running', 'cancel_requested'];
export const POLL_INTERVAL_MS = 5000;

/**
 * Controlled rollback (Task 33): status of the global switch and the runbook
 * gate (no queued/claimed/running/cancel_requested jobs). Starting (repeatable
 * drain) and stopping need the rollback role; the server enforces it
 * independently. While the rollback is active and jobs are still open, the
 * status is reloaded every few seconds.
 */
export default {
    template,

    compatConfig: Shopware.compatConfig,

    inject: ['acl', 'muwaAgentApiService'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            status: null,
            isLoading: false,
            confirmation: null,
            isConfirming: false,
            pollTimer: null,
            isUnmounted: false,
        };
    },

    computed: {
        dateFilter() {
            return Shopware.Filter.getByName('date');
        },

        canRollback() {
            return this.acl.can('muwa_agent.rollback');
        },

        isActive() {
            return this.status?.active === true;
        },

        isReady() {
            return this.status?.readyForHookRemoval === true;
        },

        openJobs() {
            return OPEN_JOB_STATES.map((state) => ({
                state,
                count: Number(this.status?.openJobs?.[state] ?? 0),
            }));
        },

        unknownJobs() {
            return Number(this.status?.unknownJobs ?? 0);
        },

        gateKey() {
            if (!this.isActive) {
                return 'gateInactive';
            }

            return this.isReady ? 'gateReady' : 'gateNotReady';
        },

        shouldPoll() {
            return this.isActive && !this.isReady;
        },
    },

    created() {
        this.loadStatus();
    },

    beforeUnmount() {
        // A status request still in flight must not schedule a new poll.
        this.isUnmounted = true;
        this.clearPoll();
    },

    methods: {
        async loadStatus() {
            this.clearPoll();
            this.isLoading = true;

            try {
                this.applyStatus(await this.muwaAgentApiService.rollbackStatus());
            } catch (exception) {
                this.notifyApiError(exception);
            } finally {
                this.isLoading = false;
            }
        },

        applyStatus(status) {
            this.status = status;
            this.schedulePoll();
        },

        schedulePoll() {
            this.clearPoll();
            if (this.isUnmounted || !this.shouldPoll) {
                return;
            }

            this.pollTimer = setTimeout(() => {
                this.loadStatus();
            }, POLL_INTERVAL_MS);
        },

        clearPoll() {
            if (this.pollTimer !== null) {
                clearTimeout(this.pollTimer);
                this.pollTimer = null;
            }
        },

        onRequestConfirmation(type) {
            this.confirmation = type;
        },

        onCancelConfirmation() {
            this.confirmation = null;
        },

        async onConfirm() {
            const type = this.confirmation;
            this.isConfirming = true;
            this.clearPoll();

            try {
                const status = type === 'start'
                    ? await this.muwaAgentApiService.startRollback()
                    : await this.muwaAgentApiService.stopRollback();
                this.createNotificationSuccess({
                    title: this.$t('global.default.success'),
                    message: this.$t(`muwa-agent.rollback.${type}Success`),
                });
                this.applyStatus(status);
            } catch (exception) {
                this.notifyApiError(exception);
                await this.loadStatus();
            } finally {
                this.isConfirming = false;
                this.confirmation = null;
            }
        },

        formatDate(value) {
            if (!value) {
                return '–';
            }

            return this.dateFilter(toUtcIso(value), { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        },

        jobStatusVariant(state, count) {
            return count > 0 ? statusVariant(state) : 'neutral';
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

/**
 * The server stores UTC timestamps without zone (`Y-m-d H:i:s.v`); mark them
 * as UTC so the date filter converts them to the user's time zone.
 */
export function toUtcIso(value) {
    const text = String(value);
    if (/[zZ]|[+-]\d{2}:?\d{2}$/.test(text)) {
        return text;
    }

    return `${text.replace(' ', 'T')}Z`;
}
