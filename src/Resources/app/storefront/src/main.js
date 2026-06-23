// PayNow Payment Storefront Plugins
// Universal storefront plugins are provided by CrehlerPaymentBundle
// Provider-specific plugins are registered here

const PluginManager = window.PluginManager;

// PayNow Card Tokenization - handles FingerprintJS device identification.
// Saved-card selection is handled by the shared CrSavedCardSelector plugin.
PluginManager.register(
    'PaynowCardTokenization',
    () => import('./cr-card-tokenization/card-tokenization'),
    '[data-paynow-card-tokenization]'
);
