// PayNow administration entry point.
// Plugin config (credential cards + the shared "test connection" button) is rendered
// from config.xml; the cr-payment-test-connection component is provided by
// CrehlerPaymentBundle's administration build.
//
// PayNow-specific: the cr-paynow-callback-urls component renders the read-only,
// copyable notification/return addresses (built from the shop URL) shown in config.xml.
import './component/cr-paynow-callback-urls';

import callbackUrlsEnGB from './translations/cr-paynow-config/en-GB.json';
import callbackUrlsPlPL from './translations/cr-paynow-config/pl-PL.json';

Shopware.Locale.extend('en-GB', callbackUrlsEnGB);
Shopware.Locale.extend('pl-PL', callbackUrlsPlPL);
