import template from './cr-paynow-callback-urls.html.twig';
import './cr-paynow-callback-urls.scss';

const { Component, Mixin } = Shopware;

const CONFIG_DOMAIN = 'CrehlerPayNowPayment.config';
const MAX_PARENT_TRAVERSAL_DEPTH = 25;

/**
 * "PayNow panel addresses" card of the plugin configuration (config.xml
 * <component name="cr-paynow-callback-urls">).
 *
 * The addresses come from the backend (GET /api/_action/crehler-paynow/shop-urls) — the
 * same resolver the configuration save uses to write them into PayNow, so the card cannot
 * disagree with what was sent. They are built from a storefront sales channel domain,
 * path included (/pl, /de), for the sales channel selected in the config page.
 *
 * The plugin writes them into PayNow itself after every save that touches credentials
 * (ShopUrlsSubscriber). "Set in PayNow" does the same on demand — with the UNSAVED form
 * credentials, like the connection test — and shows the result per environment. Copying
 * stays as the fallback when the API call is not possible.
 */
Component.register('cr-paynow-callback-urls', {
    template,

    inheritAttrs: false,

    inject: ['CrPaynowShopUrlsService'],

    // The bundle's cr-system-config-reader mixin would do the reading below, but it ships
    // in the bundle's own admin build: Mixin.getByName() runs when this file is evaluated,
    // and the load order of the two builds is not guaranteed.
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

    data() {
        return {
            shopUrls: null,
            isLoading: true,
            loadFailed: false,
            isConfiguring: false,
            results: [],
        };
    },

    computed: {
        selectedSalesChannelId() {
            return this.findSystemConfigHost()?.currentSalesChannelId ?? null;
        },

        noDomain() {
            return !this.isLoading && !this.loadFailed && this.shopUrls === null;
        },

        urls() {
            if (!this.shopUrls) {
                return [];
            }

            return [
                {
                    key: 'notification',
                    label: this.$tc('cr-paynow.callbackUrls.notificationLabel'),
                    help: this.$tc('cr-paynow.callbackUrls.notificationHelp'),
                    value: this.shopUrls.notificationUrl,
                },
                {
                    key: 'return',
                    label: this.$tc('cr-paynow.callbackUrls.returnLabel'),
                    help: this.$tc('cr-paynow.callbackUrls.returnHelp'),
                    value: this.shopUrls.returnUrl,
                },
            ];
        },
    },

    watch: {
        selectedSalesChannelId() {
            this.results = [];
            this.loadUrls();
        },
    },

    created() {
        this.loadUrls();
    },

    methods: {
        /**
         * A <component> field only gets its own props; the sibling values (credentials,
         * also unsaved) and the selected sales channel live on the sw-system-config host.
         */
        findSystemConfigHost() {
            let parent = this.$parent;

            for (let depth = 0; parent && depth < MAX_PARENT_TRAVERSAL_DEPTH; depth += 1) {
                if (parent.actualConfigData !== undefined && parent.currentSalesChannelId !== undefined) {
                    return parent;
                }

                parent = parent.$parent;
            }

            return null;
        },

        readConfigSlice() {
            const host = this.findSystemConfigHost();
            const salesChannelId = host?.currentSalesChannelId ?? null;
            const all = host?.actualConfigData?.[salesChannelId] ?? {};
            const prefix = `${CONFIG_DOMAIN}.`;
            const config = {};

            Object.keys(all).forEach((key) => {
                if (key.startsWith(prefix)) {
                    config[key.slice(prefix.length)] = all[key];
                }
            });

            return { config, salesChannelId };
        },

        async loadUrls() {
            this.isLoading = true;

            try {
                const response = await this.CrPaynowShopUrlsService.getUrls(this.selectedSalesChannelId);
                this.shopUrls = response?.urls ?? null;
                this.loadFailed = false;
            } catch (e) {
                this.shopUrls = null;
                this.loadFailed = true;
            } finally {
                this.isLoading = false;
            }
        },

        async onConfigure() {
            this.isConfiguring = true;
            this.results = [];

            try {
                const { config, salesChannelId } = this.readConfigSlice();
                const report = await this.CrPaynowShopUrlsService.configure({ salesChannelId, config });

                this.results = report?.results ?? [];
                this.notifyAboutResults();
            } catch (e) {
                this.createNotificationError({ message: this.$tc('cr-paynow.callbackUrls.configureError') });
            } finally {
                this.isConfiguring = false;
            }
        },

        notifyAboutResults() {
            const statuses = this.results.map((result) => result.status);

            if (statuses.includes('failed')) {
                this.createNotificationError({ message: this.$tc('cr-paynow.callbackUrls.configureFailed') });
            } else if (statuses.includes('ok')) {
                this.createNotificationSuccess({ message: this.$tc('cr-paynow.callbackUrls.configureOk') });
            } else {
                this.createNotificationWarning({ message: this.$tc('cr-paynow.callbackUrls.configureNoKeys') });
            }
        },

        environmentLabel(environment) {
            return this.$tc(`cr-paynow.callbackUrls.environment.${environment}`);
        },

        statusLabel(status) {
            return this.$tc(`cr-paynow.callbackUrls.status.${status}`);
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
