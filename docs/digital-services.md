# Digital Services

BlueBot sells Telegram Stars, Telegram Premium, virtual numbers and other
digital services. Orders are charged from the customer wallet and remain in
`pending_approval` until an administrator clicks **Approve & Send**.

## Admin architecture

Digital-service administration is intentionally split across the existing BlueBot panel:

- **Digital Services / فروش خدمات**: API keys, provider wallet status, profit margins and catalog sync only.
- **Services / سرویس‌ها → فروش خدمات**: create, edit, activate, categorize and delete digital products.
- **Orders / سفارش‌ها → فروش خدمات**: approve, retry, reject/refund and inspect provider references.
- **Categories / دسته‌بندی‌ها → فروش خدمات**: create, rename, order, hide and delete customer-facing categories.

This avoids duplicate product/order management screens and keeps each resource in the panel section where administrators already expect it.

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
