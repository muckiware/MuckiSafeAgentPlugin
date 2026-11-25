import template from './muwa-agent-index.html.twig';
import './muwa-agent-index.scss';

/**
 * Module shell: page header plus one tab per area. Each area is its own child
 * route so every tab carries its own route privilege.
 */
export default {
    template,

    compatConfig: Shopware.compatConfig,

    metaInfo() {
        return {
            title: this.$createTitle(),
        };
    },
};
