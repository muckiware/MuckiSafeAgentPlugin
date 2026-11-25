import template from './muwa-agent-secret-modal.html.twig';
import './muwa-agent-secret-modal.scss';

/**
 * One-time display of a freshly issued agent secret (provisioning or
 * rotation). The server never returns it again; the parent drops the value
 * as soon as this modal emits `close`.
 */
export default {
    template,

    compatConfig: Shopware.compatConfig,

    emits: ['close'],

    props: {
        issued: {
            type: Object,
            required: true,
        },
    },

    data() {
        return {
            confirmedStored: false,
        };
    },

    computed: {
        title() {
            return this.issued.mode === 'rotate'
                ? this.$t('muwa-agent.secret.rotateTitle')
                : this.$t('muwa-agent.secret.provisionTitle');
        },
    },
};
