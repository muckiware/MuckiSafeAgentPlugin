import template from './muwa-agent-action-detail.html.twig';
import './muwa-agent-action-detail.scss';

const { Mixin } = Shopware;

const DEFINITION_TEMPLATE = `{
  "version": 1,
  "steps": [
  ]
}`;

/**
 * Create/edit page of an action. The definition is edited as JSON text and
 * validated by the server (strict decoder + V1 DSL); the server stores it
 * canonically and derives hash, capabilities and retry policy. Rejected
 * fields and the DSL path of a schema error are shown inline.
 */
export default {
    template,

    compatConfig: Shopware.compatConfig,

    inject: ['repositoryFactory', 'acl', 'muwaAgentApiService'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    props: {
        actionId: {
            type: String,
            required: false,
            default: null,
        },
    },

    data() {
        return {
            action: this.emptyAction(),
            definitionText: DEFINITION_TEMPLATE,
            isLoading: false,
            isValidating: false,
            processSuccess: false,
            fieldErrors: [],
            definitionError: null,
            validation: null,
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle(this.action.label || null),
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('muwa_agent_action');
        },

        isNew() {
            return !this.actionId;
        },

        canEdit() {
            return this.acl.can('muwa_agent.editor');
        },

        idempotencyOptions() {
            return ['safe', 'non_retryable'].map((value) => ({
                value,
                label: this.$t(`muwa-agent.action.idempotencyClasses.${value}`),
            }));
        },

        retryPolicyText() {
            return this.$t(`muwa-agent.action.retryPolicies.${this.action.idempotencyClass}`);
        },
    },

    watch: {
        // Create and detail share this component; the router reuses it after the first save.
        actionId(actionId) {
            this.fieldErrors = [];
            if (actionId) {
                this.loadAction();
                return;
            }
            this.action = this.emptyAction();
            this.definitionText = DEFINITION_TEMPLATE;
        },

        definitionText() {
            this.validation = null;
            this.definitionError = null;
        },
    },

    created() {
        if (!this.isNew) {
            this.loadAction();
        }
    },

    methods: {
        emptyAction() {
            return {
                technicalName: '',
                label: '',
                enabled: true,
                defaultTimeoutSeconds: 300,
                maxTimeoutSeconds: 600,
                idempotencyClass: 'safe',
                requiredCapabilities: [],
            };
        },

        async loadAction() {
            this.isLoading = true;
            try {
                const entity = await this.repository.get(this.actionId, Shopware.Context.api);
                if (!entity) {
                    return;
                }
                this.action = {
                    technicalName: entity.technicalName,
                    label: entity.label,
                    enabled: entity.enabled,
                    defaultTimeoutSeconds: entity.defaultTimeoutSeconds,
                    maxTimeoutSeconds: entity.maxTimeoutSeconds,
                    idempotencyClass: entity.idempotencyClass,
                    requiredCapabilities: entity.requiredCapabilities || [],
                };
                this.definitionText = this.prettyPrint(entity.definition);
            } finally {
                this.isLoading = false;
            }
        },

        prettyPrint(json) {
            try {
                return JSON.stringify(JSON.parse(json), null, 2);
            } catch {
                return json;
            }
        },

        fieldError(field) {
            if (!this.fieldErrors.includes(field)) {
                return null;
            }

            return {
                code: 'MUWA_AGENT_INVALID_FIELD',
                detail: this.$t(`muwa-agent.action.fieldErrors.${field}`),
            };
        },

        buildPayload() {
            const payload = {
                technicalName: this.action.technicalName.trim(),
                label: this.action.label,
                enabled: this.action.enabled,
                definition: this.definitionText,
                defaultTimeoutSeconds: this.action.defaultTimeoutSeconds,
                maxTimeoutSeconds: this.action.maxTimeoutSeconds,
                idempotencyClass: this.action.idempotencyClass,
            };
            if (!this.isNew) {
                payload.id = this.actionId;
            }

            return payload;
        },

        async onValidate() {
            this.isValidating = true;
            this.definitionError = null;
            try {
                this.validation = await this.muwaAgentApiService.validateAction(this.definitionText);
            } catch (exception) {
                this.handleError(exception);
            } finally {
                this.isValidating = false;
            }
        },

        async onSave() {
            this.isLoading = true;
            this.processSuccess = false;
            this.fieldErrors = [];
            this.definitionError = null;
            try {
                const { id } = await this.muwaAgentApiService.saveAction(this.buildPayload());
                this.processSuccess = true;
                this.createNotificationSuccess({
                    title: this.$t('global.default.success'),
                    message: this.$t('muwa-agent.action.saveSuccess'),
                });
                if (this.isNew) {
                    this.$router.push({ name: 'muwa.agent.actionDetail', params: { id } });
                    return;
                }
                await this.loadAction();
            } catch (exception) {
                this.handleError(exception);
            } finally {
                this.isLoading = false;
            }
        },

        handleError(exception) {
            const data = exception?.response?.data || {};
            if (data.error === 'invalid_definition') {
                this.definitionError = data.detail;
                return;
            }
            if (data.error === 'technical_name_taken') {
                this.fieldErrors = ['technicalName'];
            } else if (Array.isArray(data.fields)) {
                this.fieldErrors = data.fields;
            }
            const key = `muwa-agent.errors.${data.error}`;
            this.createNotificationError({
                title: this.$t('global.default.error'),
                message: data.error && this.$te(key) ? this.$t(key) : this.$t('muwa-agent.errors.operation_failed'),
            });
        },

        saveFinish() {
            this.processSuccess = false;
        },
    },
};
