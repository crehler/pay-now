import Plugin from 'src/plugin-system/plugin.class';

/**
 * PayNow Card Tokenization Plugin
 *
 * Handles the PayNow-specific tokenization only:
 * - FingerprintJS loading for device identification
 *
 * Saved-card dropdown selection is handled by the shared bundle plugin
 * cr-saved-card-selector (registered on [data-saved-card-selector]).
 */
export default class PaynowCardTokenization extends Plugin {
    static options = {
        fingerprintScriptUrl: 'https://static.paynow.pl/scripts/PyG5QjFDUI.min.js',
        visitorIdSelector: '#paynowVisitorId',
    };

    init() {
        this._visitorIdInput = this.el.querySelector(this.options.visitorIdSelector);

        this._loadFingerprint();
    }

    /**
     * Load PayNow FingerprintJS and get visitor ID
     */
    async _loadFingerprint() {
        try {
            const FingerprintJS = await import(this.options.fingerprintScriptUrl);
            const fp = await FingerprintJS.load();
            const result = await fp.get();

            if (this._visitorIdInput) {
                this._visitorIdInput.value = result.visitorId;
            }

            console.debug('[PayNow] Fingerprint loaded:', result.visitorId);
        } catch (error) {
            console.error('[PayNow] Failed to load fingerprint:', error);
        }
    }
}
