import template from './cr-paynow-callback-urls.html.twig';
import './cr-paynow-callback-urls.scss';

const { Component, Mixin } = Shopware;

/**
 * Read-only, copyable callback addresses that the merchant must paste into the
 * PayNow merchant panel: the notification (webhook) URL and the return URL.
 *
 * Both are derived from the shop URL the admin is served on (window.location.origin),
 * which equals APP_URL in a standard install:
 *   - notification: {APP_URL}/payment/notification  (shared CrehlerPaymentBundle webhook)
 *   - return:       {APP_URL}
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

    props: {
        // Bound by sw-form-field-renderer from the config.xml element name (unused — display only).
        name: {
            type: String,
            required: false,
            default: '',
        },
    },

    computed: {
        baseUrl() {
            return window.location.origin;
        },

        notificationUrl() {
            return `${this.baseUrl}/payment/notification`;
        },

        returnUrl() {
            return this.baseUrl;
        },

        urls() {
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

    methods: {
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
