# Changelog

> The `version` in `composer.json` is the **last released** one — `publish.yml` writes it during
> the release run, so it never points at the section below. Unreleased entries get their number
> when that workflow runs.

## Unreleased

### Fixed

- **Notification address in the plugin config pointed at the bare host (WT-1029).** The
  „Adresy do panelu PayNow" card built both addresses from the admin's
  `window.location.origin`. A shop whose sales channel domains all carry a path suffix
  (`/pl`, `/en`) has no channel at the bare host, so the suggested
  `https://shop/payment/notification` matched nothing. The card now builds the addresses from
  a storefront sales channel domain, path included, and says that the same address serves the
  production and the sandbox panel. The return address the plugin sends with each payment is
  fixed in crehler/payment-bundle 6.6.1.

### Changed — breaking

- **Technical name `CrehlerPayNow` → `CrehlerPayNowPayment`, namespace `Crehler\PayNow` →
  `Crehler\PayNowPayment` (WT-1024).** It is the technical name of the plugin already
  published in the Shopware Store (legacy 4.x line), which cannot be renamed there — so 6.x
  now ships to those shops as an update. Everything derived from the name moves with it:
  config domain `CrehlerPayNowPayment.config.*`, Twig/asset namespace `@CrehlerPayNowPayment`,
  public assets under `bundles/crehlerpaynowpayment/`, the ZIP `CrehlerPayNowPayment.zip`.
  The Composer package stays `crehler/pay-now`.
- **Update from 4.x.** The 4.x payment method (handler class that no longer exists) is
  deactivated — kept, because past orders reference it. Credentials move to the 6.x keys of
  the environment they were used for (`ApiKey`/`SingatureKeyLabel` → production or sandbox
  keys, following `EnableSandbox` per sales channel). The new payment methods (BLIK, bank,
  card, PayPo) have to be assigned to the sales channels by the merchant. `composer.json`
  replaces the 4.x package `crehler-pay-now-payment/shopware-integration`, without which the
  Composer run Shopware performs on update fails on the root requirement 4.x left behind.
- **Install over an early 6.x `CrehlerPayNow`.** Shopware sees a different plugin: the
  configuration is copied from `CrehlerPayNow.config.*`, payment methods are taken over by
  technical name. The old `CrehlerPayNow` entry has to be removed from the plugin list.

### Added

- Readable error instead of a class-not-found fatal when Shopware did not install the
  Composer dependencies (it skips that step without a word on `cluster_setup` shops).

### Fixed

- **Basket sent with line totals as unit prices.** `orderItems[].price` is the price of one
  unit in PayNow's schema (the gateway multiplies it by `quantity`), but the plugin sent the
  line total there — a basket of 3 × 100 zł reached PayNow, and PayPo's underwriting, as
  3 × 300 zł. The unit price goes out now.
- **Retrying with a different bank opened the previously chosen one.** The idempotency key
  was derived from the order transaction alone, and Shopware reopens that same transaction
  when a customer retries with the same payment method — so a switch from one bank to
  another reached PayNow under the original key and the gateway replayed the original
  payment. The key now identifies the payment attempt (transaction + sub-method), which
  keeps the double-charge guard for a repeated submit while letting a genuinely different
  choice create a new payment.
- **BLIK: rejected payments no longer reported as success.** The gateway status from the
  authorization response went to the log only, so every rejected BLIK code still produced a
  success result — `POST /store-api/cr/payment/blik` answered `success: true` with an empty
  error field. A terminal rejection now fails the payment.
- **Refunds now reach „Zakończony".** PayNow accepts a refund as `PENDING` and sends no
  notification when it settles, so refunds stayed „W toku" forever. A refund-status provider
  is polled by the bundle's hourly job (`GET /v3/refunds/{id}/status`).
- **Admin transaction details reported production for sandbox payments.** The sandbox flag
  was never passed, so an operator checking whether a transaction was real got the wrong
  answer. It is now read from the same config as the client that was called.
- **One terminal state for a rejected payment.** A rejection answered synchronously ended up
  „nieudane" while the same rejection reported by notification ended up „anulowane"; both are
  „nieudane" now, so the order list and filters stop disagreeing with themselves.
- **Webhook no longer answers the gateway with an HTML page.** A non-UUID `externalId` blew up
  the transaction lookup performed during verification; the exception escaped and the gateway
  received a full storefront error page. This also revived the order-number fallback, which
  had been unreachable dead code.
- **Card section in checkout rendered empty.** The card form moved to its own namespaced
  template behind the bundle's new `CardFormTemplateProviderPort`; a Twig block override could
  never reach the output because of a template path collision. Removed with it: references to
  two card-brand icons that are not in the package, and a „Zapisz kartę" checkbox nothing read.
- **Connection test message translated.** Hardcoded English „Connection successful." in an
  otherwise Polish admin; the admin's own translated confirmation is used now.

### Notes

- Saved cards (tokens) are still not implemented end-to-end. PayNow keeps them gateway-side
  (`savedInstruments` returned by `getPaymentMethods` for an `externalBuyerId`), and the plugin
  never sends a buyer identifier, so there is nothing to associate a card with. Tracked
  separately — it needs the card method enabled at the payment point to verify.

## 6.2.1

Released 2026-07-22 with no entries recorded in this file.

## 6.2.0

Released 2026-07-17 with no entries recorded in this file.

## 6.0.2

Released 2026-07-17 with no entries recorded in this file.

## 6.0.1

### Fixed

- **Admin order detail — gateway transaction details.** `PayNowGatewayDetailsProvider::mapLevel()`
  returned a `GatewayStatusLevel` enum case from a method typed `: string`, causing a `TypeError`
  so the gateway-details tab failed with „Nie udało się pobrać szczegółów transakcji z bramki".
  It now returns the enum's string value.

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
