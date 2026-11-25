import template from './muwa-agent-provision-modal.html.twig';

/**
 * Asks for the agent name and provisions it. The server response (id, keyId,
 * secret) is emitted once to the parent and not kept in this component.
 */
export default {
    template,

    compatConfig: Shopware.compatConfig,

    inject: ['muwaAgentApiService'],

    emits: ['provisioned', 'error', 'close'],

    data() {
        return {
            name: '',
            isLoading: false,
        };
    },

    computed: {
        trimmedName() {
            return this.name.trim();
        },

        isValid() {
            return this.trimmedName.length > 0 && this.trimmedName.length <= 255;
        },
    },

    methods: {
        async onProvision() {
            if (!this.isValid || this.isLoading) {
                return;
            }

            this.isLoading = true;
            try {
                const result = await this.muwaAgentApiService.provision(this.trimmedName);
                this.$emit('provisioned', { ...result, name: this.trimmedName });
            } catch (exception) {
                this.$emit('error', exception);
            } finally {
                this.isLoading = false;
            }
        },
    },
};
