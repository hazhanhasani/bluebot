# Digital Services

BlueBot sells Telegram Stars, Telegram Premium, virtual numbers and other
digital services. Orders are charged from the customer wallet and remain in
`pending_approval` until an administrator clicks **Approve & Send**.

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
- `GET /numbers/getAllOrders/`
- `GET /numbers/getOpenOrders/`
- `GET /numbers/getOrder/`
- `POST /numbers/cancelOrder/`

Stars, Premium packages and available virtual-number countries are synchronized
into `digital_service_products`. The configured OZVinoo profit percentage is
applied to every imported wholesale price. Missing products are disabled only
when their corresponding endpoint synchronized successfully.

For virtual numbers, checkout does not ask the customer for an external target.
After administrator approval BlueBot reserves the selected country, shows the
number when available and polls the order until the Telegram verification code
arrives.

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
- `digital_service_settings`
- `digital_service_providers`
