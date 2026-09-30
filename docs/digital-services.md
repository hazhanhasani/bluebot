# Digital Services

BlueBot sells Telegram Stars, Telegram Premium, virtual numbers and provider-backed
SMM/digital services. Checkout is wallet-based and each provider has an independent
delivery mode. Manual providers keep the order in `pending_approval` until an
administrator approves it; automatic providers claim the same order atomically and
send it to the provider immediately. Retryable automatic failures fall back to
administrator review without exposing provider internals to the customer.

## Admin architecture

Digital-service administration is intentionally split across the existing BlueBot panel:

- **Digital Services / فروش خدمات**: API keys, provider wallet status, profit margins and catalog sync only.
- **Services / سرویس‌ها → فروش خدمات**: create, edit, activate, categorize and delete digital products.
- **Orders / سفارش‌ها → فروش خدمات**: approve, retry, reject/refund and inspect provider references.
- **Categories / دسته‌بندی‌ها → فروش خدمات**: create, rename, order, hide and delete customer-facing categories.

This avoids duplicate product/order management screens and keeps each resource in the panel section where administrators already expect it.

## Customer checkout

The customer-facing flow is intentionally staged so the payable amount and target
are explicit before the wallet is charged:

1. Choose the platform/category.
2. Choose the scope where applicable (for example Telegram channel, group, post,
   story, bot or account).
3. Choose the service type (members, views, reactions, comments, likes, etc.).
4. Choose the product.
5. For variable-quantity SMM services, enter a quantity within the provider's
   advertised minimum and maximum.
6. Enter the destination using a context-aware prompt (post link, channel/group
   link, profile, or Telegram username depending on the product).
7. Review quantity, target, exact Toman amount, current wallet balance and the
   post-purchase balance.
8. Confirm once. The order intent is consumed under a user-row lock so duplicate
   Telegram callbacks cannot double-charge the wallet.
9. Track the order from **سفارش‌های من**.

### Variable SMM quantities and pricing

Standard SMM catalogs publish a `rate` per 1000 units plus `min`/`max`.
BlueBot stores those wholesale values in product metadata instead of forcing the
customer to buy only the provider minimum. At checkout the selected quantity is
validated against the latest product metadata and the retail price is recalculated
from:

`wholesale = rate_per_1000 × quantity / 1000`

The normal currency conversion and provider profit percentage are then applied,
followed by BlueBot's normal price rounding. The final quantity and amount are
snapshotted into `digital_service_orders`; provider delivery uses that order
quantity rather than the catalog's minimum package.

Fixed products such as Stars, Premium and virtual numbers keep their existing
fixed quantity/package behavior.

### Customer order status

Customers can open **سفارش‌های من** from the Digital Services menu. The view is
paginated and deliberately uses customer-facing status language:

- registered / waiting for processing
- processing
- completed
- under review
- cancelled/refunded
- failed/refunded

Provider references and raw provider error messages remain admin-facing.

### Nobitex API host and optional API Key
> **Signature encoding:** Nobitex's documented `urlsafe_b64encode` output
> includes Base64 padding. BlueBot preserves trailing `=` characters in
> `Nobitex-Signature`; stripping them can cause an HTTP 400 during API-key
> validation.


BlueBot uses `https://apiv2.nobitex.ir` for Nobitex API traffic. Public
GRAMIRT orderbook and system-options requests do not require authentication.

Nobitex API Keys are optional and can be configured from the TGTools provider
card. The current Nobitex API-Key scheme requires **both** values returned when
the key is created:

- `key` → sent as `Nobitex-Key`
- `privateKey` → Ed25519 private key used locally to sign requests

BlueBot never sends the private key itself. It signs
`timestamp + METHOD + full_path + raw_body` locally and sends the resulting
`Nobitex-Signature` plus `Nobitex-Timestamp`. The “test API key” control
checks `GET /users/profile`, so a key with `READ` permission is sufficient.

A missing/invalid API Key does not stop public GRAM pricing; public pricing and
withdrawal-option sync remain independent.

### Live TON/GRAM pricing from Nobitex
> **Currency unit note:** Nobitex orderbook prices are consumed as Rial-denominated
> values by BlueBot. Before TGTools pricing, `lastTradePrice` is divided by
> `10` to obtain Toman. For example, a raw quote of `3,905,670` becomes
> `390,567 Toman`.


TGTools wholesale prices are denominated in TON. BlueBot no longer requires an
administrator to maintain a manual TON/Toman conversion rate.

- Source: Nobitex public market API.
- Market: `GRAMIRT`, as requested for the legacy TON/Gram market.
- Endpoint: `GET https://apiv2.nobitex.ir/v3/orderbook/GRAMIRT`.
- Price field: `lastTradePrice`.
- Authentication: none.
- Refresh cadence: once per minute from the digital-services cron.
- Failure behavior: the last successful rate is kept; products are not repriced
  to zero when Nobitex is temporarily unavailable.
- Repricing: existing TGTools Stars/Premium products are recalculated from their
  stored `wholesale_ton` metadata without requiring another TGTools catalog
  request.

The provider panel exposes the current cached rate, source market, last refresh
time and the last fetch error, plus a manual “refresh now” button.

## Providers

- `tgtools`: Stars and Premium through TGTools.
- `ozvinoo`: Stars, Premium and Telegram virtual numbers through the official
  OZVinoo REST endpoints at `https://api.ozvinoo.xyz`.
- `telegram_bot`: Telegram Premium through Bot API where supported.
- `manual`: administrator-managed fulfillment.
- Generic catalog providers remain supported separately through the provider
  registry.

## OZVinoo / Callinoo

Configure the API token in **Panel → Digital Services → OZVinoo**. BlueBot no
longer probes the provider's OpenAPI root or treats OZVinoo as a generic SMM
panel.

Official endpoints used by BlueBot:

- `GET /web/{token}/get-balance`
- `GET/POST /telegram-services/stars/`
- `GET/POST /telegram-services/premium/`
- `GET /telegram-services/status/`
- `GET/POST /telegram-numbers/numbers/`
- `GET/POST /telegram-numbers/number-services/`
- `GET /web/{token}/applications` (virtual-number fallback)
- `GET /web/{token}/get-prices/{service_id}` (virtual-number fallback)
- `POST /web/{token}/getNumber/{service_id}/{country}` (virtual-number fallback)
- `GET /web/{token}/getCode/{request_id}` (virtual-number fallback)
- `GET /numbers/getAllOrders/`
- `GET /numbers/getOpenOrders/`
- `GET /numbers/getOrder/`
- `POST /numbers/cancelOrder/`

Stars, Premium packages and available virtual-number countries are synchronized
into `digital_service_products`. The configured OZVinoo profit percentage is
applied to every imported wholesale price. Missing products are disabled only
when their corresponding endpoint synchronized successfully.

For virtual numbers, BlueBot mirrors Callinoo's application-first flow instead
of treating the catalog as Telegram-only:

1. **Virtual Number** opens a platform/application picker (Telegram, WhatsApp,
   Google, Instagram, Discord, Apple and every other application returned by
   `/web/{token}/applications`).
2. Selecting a platform opens its available countries from
   `/web/{token}/get-prices/{service_id}`.
3. Country lists are paginated so Telegram never receives an oversized inline
   keyboard.
4. Checkout does not ask for an external target. After administrator approval,
   BlueBot reserves the selected platform/country number and polls the matching
   provider flow until the verification code arrives.

The V2 Telegram-number endpoint remains only as a fallback when the Callinoo
application catalog is temporarily unavailable.

### Nobitex GRAM withdrawal cost

BlueBot also includes the exchange withdrawal cost between Nobitex and the
operator wallet. The current public Nobitex pricing page lists GRAM on the TON
network with a `0.1 GRAM` withdrawal fee and a `0.2 GRAM` minimum withdrawal.

BlueBot reads the current GRAM/TON withdrawal terms from the public
`GET /v2/options` system-options endpoint and caches them. If that endpoint is
temporarily unavailable, the last successful value is retained. The initial
safe defaults are `0.1 GRAM` withdrawal fee and `0.2 GRAM` minimum.

The landed-cost path is therefore:

`Nobitex market purchase + trading fee + Nobitex withdrawal fee + wallet→TGTools network fee`.

The separate wallet-to-TGTools network fee remains configurable because it is
the fee observed on the final wallet transfer rather than a Nobitex fee.

### TGTools landed GRAM cost

BlueBot prices TGTools products from the **landed** GRAM cost instead of the raw
Nobitex quote. This includes the costs required to move GRAM from the Toman
market into the TGTools wallet.

Defaults are based on the operator's observed costs:

- Nobitex Toman-market fee: `0.25%`
- GRAM network transfer fee: `0.000562 GRAM`
- Typical TGTools funding batch: `1 GRAM`

The effective landed rate is calculated as:

`landed_rate = market_rate × (1 / (1 - trade_fee)) × ((batch + network_fee) / batch)`

The TGTools retail price remains:

`retail = wholesale_ton × landed_rate × (1 + profit_percent)`

BlueBot then applies its existing upward thousand-Toman rounding. The fee
inputs are editable because exchange tiers, network fees, and funding batch
sizes can change over time. Increasing the funding batch amortizes the fixed
network fee across more GRAM.


### Callinoo application discovery diagnostics

The documented Callinoo `/web/{token}/applications` and
`/web/{token}/get-prices/{service_id}` endpoints accept both GET and POST.
BlueBot uses GET first and, when the application list is empty or contains only
one platform, probes POST and merges unique application IDs. Country/price
requests similarly fall back to POST when GET returns no usable rows.

The provider panel exposes the GET/POST application counts after every sync.
If both methods expose only one application, BlueBot does not invent hidden
service IDs; this indicates the connected Callinoo API token/catalog itself is
only exposing that application.

### Catalog schema migration

BlueBot stores an `ozvinoo_catalog_schema_version` marker. When a release
changes the Callinoo catalog hierarchy, the background job ignores the normal
freshness window once and performs a full application/catalog refresh. This is
important for installations upgraded from the old Telegram-only virtual-number
catalog: old V1 rows are refreshed with their real application metadata instead
of remaining grouped under a generic “virtual number service”.

## Admin visibility overrides

When an administrator disables a digital service from **Services → Digital
Services**, BlueBot stores an `admin_disabled` flag in the product metadata.
Provider synchronization may continue updating price, inventory and provider
metadata, but it cannot re-enable that service. Clicking **Enable** removes the
manual lock and returns the product to provider-managed availability.

This rule applies consistently to OZVinoo/Callinoo, TGTools and generic catalog
providers.

## Manual and automatic delivery

Every supported provider can keep its own approval mode where an automatic
delivery adapter exists. Manual mode notifies administrators and waits for
**Approve & Send**. Automatic mode uses the same atomic approval claim, sends the
order immediately and lets cron reconcile asynchronous provider status.

A retryable automatic failure does not show API/provider details to the customer.
The customer sees the order as registered/processing while the administrator gets
the provider error and can safely retry or reject/refund. Non-retryable failure
paths that are safe to classify as final refund the wallet atomically.

## Runtime diagnostics

The admin-only Telegram `/debug` report includes a Digital Services health
section without exposing API keys or credentials. It reports active product count,
pending orders, processing orders, unresolved failed orders requiring review,
delivered orders, and each registered provider's enabled state, manual/automatic
mode and latest sync state.

## Background reconciliation

`cronbot/digital_services.php` refreshes provider catalogs and reconciles
processing orders. Stars/Premium orders are checked through the official status
endpoint; virtual-number orders are checked through
`/telegram-numbers/number-services/`.

## Database

Run the normal updater or:

```bash
php table.php
```

The module uses:

- `digital_service_products`
- `digital_service_orders`
- `digital_service_categories`
- `digital_service_settings`
- `digital_service_providers`

The category schema is also created defensively at runtime for upgraded installations, so an existing host does not lose the new Categories UI if a database migration step was skipped.
