# Changelog

## 6.0.0

First public release on `crehler/pay-now`.

Built on the shared `crehler/payment-bundle` (`^6.0 >=6.0.2`): the plugin implements only the
PayNow-specific pieces (PHP SDK client, signatures, status mapping) and inherits the lifecycle,
checkout UI, webhooks, sub-methods and transition page from the bundle.

### Features

- **BLIK (layer zero)** — the customer enters the BLIK code in the shop and pays without any
  redirect.
- **Card** — redirect to PayNow's hosted, secure payment page (3-D Secure); card data never
  touches the shop (fully-hosted model, minimal PCI scope). Saved cards (tokens) for returning
  customers.
- **Pay-by-link** — bank selection (sub-methods pulled from the PayNow API), with the selected
  bank remembered for the next order.
- **Refunds** — full and partial refunds from the Shopware admin order view (PayNow's predefined
  refund-reason list).
- **Webhook verification** — payment notifications verified with the signature key.
- **RODO / consent** — PayNow data-processing notices surfaced in checkout.
- **Callback addresses in config** — read-only, copyable notification (`/payment/notification`)
  and return URLs, built from the shop URL, to paste into the PayNow merchant panel.
- **Headless** — full Store API support (BLIK layer zero, bank sub-methods, payment status)
  alongside the classic Storefront.

### Requires

- PHP `~8.2 || ~8.3 || ~8.4 || ~8.5`, Shopware `~6.6 || ~6.7`
- `crehler/payment-bundle: ^6.0 >=6.0.2`
- `pay-now/paynow-php-sdk: ^2.4`
