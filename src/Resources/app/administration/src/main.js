// PayNow administration entry point.
// Plugin config (credential cards + the shared "test connection" button) is rendered
// from config.xml; the cr-payment-test-connection component is provided by
// CrehlerPaymentBundle's administration build.
//
// PayNow-specific: the cr-paynow-callback-urls component shows the notification/return
// addresses (built on the backend from a sales channel domain) and sets them in PayNow.
import './component/cr-paynow-callback-urls';
import CrPaynowShopUrlsService from './service/cr-paynow-shop-urls.service';

import callbackUrlsEnGB from './translations/cr-paynow-config/en-GB.json';
import callbackUrlsPlPL from './translations/cr-paynow-config/pl-PL.json';

Shopware.Locale.extend('en-GB', callbackUrlsEnGB);
Shopware.Locale.extend('pl-PL', callbackUrlsPlPL);

Shopware.Application.addServiceProvider('CrPaynowShopUrlsService', (container) => {
    const initContainer = Shopware.Application.getContainer('init');
    return new CrPaynowShopUrlsService(initContainer.httpClient, container.loginService);
});
