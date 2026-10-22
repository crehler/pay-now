const ApiService = Shopware.Classes.ApiService;

/**
 * GET/POST /api/_action/crehler-paynow/shop-urls — the PayNow notification and return
 * addresses for a sales channel scope, and setting them in PayNow (PATCH
 * /v3/configuration/shop/urls on the backend). Credentials travel in the POST body only.
 */
class CrPaynowShopUrlsService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = '_action/crehler-paynow') {
        super(httpClient, loginService, apiEndpoint);
    }

    getUrls(salesChannelId = null) {
        return this.httpClient
            .get(`${this.getApiBasePath()}/shop-urls`, {
                params: salesChannelId ? { salesChannelId } : {},
                headers: this.getBasicHeaders(),
            })
            .then((response) => ApiService.handleResponse(response));
    }

    configure({ salesChannelId = null, config = {} }) {
        // A failed environment answers 502 with the same report in the body — resolve it
        // either way so the card can show per-environment results.
        return this.httpClient
            .post(
                `${this.getApiBasePath()}/shop-urls`,
                { salesChannelId, config },
                { headers: this.getBasicHeaders(), validateStatus: (status) => status === 200 || status === 502 },
            )
            .then((response) => response.data);
    }
}

export default CrPaynowShopUrlsService;
