import template from './cr-paynow-callback-urls.html.twig';
import './cr-paynow-callback-urls.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

// Defaults::SALES_CHANNEL_TYPE_STOREFRONT
const STOREFRONT_TYPE_ID = '8a243080f92e4c719546314b577cf82b';

/**
 * Read-only, copyable callback addresses that the merchant must paste into the
 * PayNow merchant panel: the notification (webhook) URL and the return URL.
 *
 * Both are built from a storefront sales channel DOMAIN, path suffix included:
 *   - notification: {domain}/payment/notification  (shared CrehlerPaymentBundle webhook)
 *   - return:       {domain}
 *
 * Not from window.location.origin: a shop whose domains carry a path suffix
 * (https://shop/pl, https://shop/en) has no sales channel at the bare host, so
 * https://shop/payment/notification matches nothing there (WT-1029). Any storefront
 * domain works — the webhook finds the transaction by the gateway id, not by the
 * channel it arrives on. The origin is only the fallback when the shop has no storefront
 * domain at all; when the lookup itself fails (e.g. missing ACL) no address is shown,
 * because the origin is exactly the address that breaks suffixed shops.
 *
 * Display-only — rendered in config.xml via <component name="cr-paynow-callback-urls">;
 * it persists nothing.
 */
Component.register('cr-paynow-callback-urls', {
    template,

    inheritAttrs: false,

    mixins: [
        Mixin.getByName('notification'),
    ],

    inject: ['repositoryFactory'],

    props: {
        // Bound by sw-form-field-renderer from the config.xml element name (unused — display only).
        name: {
            type: String,
            required: false,
            default: '',
        },
    },

    data() {
        return {
            domainUrl: null,
            isLoading: true,
            domainLoadFailed: false,
        };
    },

    computed: {
        baseUrl() {
            return (this.domainUrl ?? window.location.origin).replace(/\/+$/, '');
        },

        notificationUrl() {
            return `${this.baseUrl}/payment/notification`;
        },

        returnUrl() {
            return this.baseUrl;
        },

        urls() {
            if (this.isLoading || this.domainLoadFailed) {
                return [];
            }

            return [
                {
                    key: 'notification',
                    label: this.$tc('cr-paynow.callbackUrls.notificationLabel'),
                    help: this.$tc('cr-paynow.callbackUrls.notificationHelp'),
                    value: this.notificationUrl,
                },
                {
                    key: 'return',
                    label: this.$tc('cr-paynow.callbackUrls.returnLabel'),
                    help: this.$tc('cr-paynow.callbackUrls.returnHelp'),
                    value: this.returnUrl,
                },
            ];
        },
    },

    created() {
        this.loadDomainUrl();
    },

    methods: {
        async loadDomainUrl() {
            const criteria = new Criteria(1, 1);
            criteria.addFilter(Criteria.equals('salesChannel.typeId', STOREFRONT_TYPE_ID));
            criteria.addSorting(Criteria.sort('url', 'ASC'));

            try {
                const domains = await this.repositoryFactory.create('sales_channel_domain').search(criteria);
                this.domainUrl = domains.first()?.url ?? null;
                this.domainLoadFailed = false;
            } catch (e) {
                this.domainUrl = null;
                this.domainLoadFailed = true;
                this.createNotificationError({ message: this.$tc('cr-paynow.callbackUrls.domainLoadError') });
            } finally {
                this.isLoading = false;
            }
        },

        async onCopy(value) {
            try {
                await navigator.clipboard.writeText(value);
                this.createNotificationSuccess({ message: this.$tc('cr-paynow.callbackUrls.copied') });
            } catch (e) {
                this.createNotificationError({ message: this.$tc('cr-paynow.callbackUrls.copyError') });
            }
        },
    },
});
