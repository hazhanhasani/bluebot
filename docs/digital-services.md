# Digital Services

BlueBot's digital-services module sells non-VPN products such as Telegram Stars,
Telegram Premium, manually delivered products, and products fulfilled by an
external provider.

## Safety rule: manual approval before delivery

Creating an order never calls Telegram or an external provider. The wallet is
charged and the order enters `pending_approval`. Delivery starts only after an
administrator explicitly clicks **Approve & Send** in the Telegram admin message
or the web panel.

An administrator can reject a pending/failed order. BlueBot refunds the wallet
inside the same database transaction and marks the order as `rejected`.

## Providers

- `manual`: the administrator performs fulfillment externally and then confirms
  the send action.
- `telegram_bot`: currently supports Telegram Premium through
  `giftPremiumSubscription`. Automatic Premium delivery accepts numeric Telegram
  user IDs and supports 3, 6, or 12 months.
- `ozvinoo`: HTTPS-only adapter restricted to `api.ozvinoo.xyz`. Configure the
  exact order endpoint and API credentials in **Panel > Digital Services** after
  confirming them against the provider documentation.

Telegram Stars are not sent directly through Bot API. Configure Stars products
as `manual` or use a supported provider such as OZVinoo.

## Database

Run the normal BlueBot updater or:

```bash
php table.php
```

The schema adds:

- `digital_service_products`
- `digital_service_orders`
- `digital_service_settings`

## Main keyboard

The main-menu token is `text_digital_services`. It is present in the default
keyboard and can be enabled/disabled in the keyboard settings page.
