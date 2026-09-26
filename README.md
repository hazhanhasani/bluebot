<div align="center">

# 🔵 BlueBot

### Telegram VPN sales, automation and subscription management platform

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-AGPL--3.0-blue?style=flat-square)](LICENSE)
[![GitHub](https://img.shields.io/badge/GitHub-hazhanhasani%2Fbluebot-181717?style=flat-square&logo=github)](https://github.com/hazhanhasani/bluebot)

</div>

---

## Overview

**BlueBot** is a self-hosted Telegram platform for selling and managing VPN subscriptions. It automates the customer flow from payment and service creation to renewals, balance management, reminders, configuration delivery and administration.

The project includes a Telegram bot, web administration panel, Telegram Mini App, installer/update workflow, database migrations, payment integrations and support for multiple VPN management panels.

## Main capabilities

- Automated VPN sales and configuration delivery
- Trial accounts, renewals and extra-volume purchases
- Customer wallet and balance management
- Web admin panel and Telegram Mini App
- Multiple administrators and role-aware management
- Discount, gift, referral, cashback and reseller features
- Automatic backups, cron jobs and expiry notifications
- QR-code generation and subscription links
- Multi-language support
- Release bundles generated through GitHub Actions

## Supported panels

BlueBot currently contains integrations for:

- Marzban
- Marzneshin
- Sanaei / Alireza
- S-UI
- Hiddify
- WGDashboard / WireGuard
- MikroTik
- IBSng
- PasarGuard

## Payment integrations

The repository includes support for manual card-to-card flows and multiple online/crypto gateways, including gateway-specific callback/webhook handlers under `payment/`.

## Requirements

- Ubuntu 22.04 or 24.04 recommended
- Root access for the automated installer
- Domain pointed to the server
- PHP 8.2+
- MySQL
- Apache
- Composer

The installer can provision the required web stack on a clean server.

## Installation

Run:

```bash
curl -o install.sh -L https://raw.githubusercontent.com/hazhanhasani/bluebot/main/install.sh
bash install.sh
```

After installation, the management command is:

```bash
bluebot
```

For backward compatibility with existing installations, the legacy `mirza` command may continue to be available during the transition.

## Update

Run the same installer and select the update option, or use:

```bash
bluebot update --channel release
```

Beta/main builds can be selected with:

```bash
bluebot update --channel beta
```

## Repository structure

```text
.
├── admin.php              # Web/admin application
├── index.php              # Main Telegram bot entry point
├── function.php           # Shared application/business helpers
├── keyboard.php           # Telegram keyboards and menu definitions
├── panels.php             # Panel management flows
├── app/                   # Telegram Mini App
├── api/                   # API endpoints
├── db/                    # Schema, migrations and database bootstrap
├── payment/               # Payment gateways and callbacks
├── cronbot/               # Scheduled jobs
├── lang/                  # Translations
├── install/               # Web installer resources
├── install.sh             # Server installer/updater
└── .github/workflows/     # Release automation
```

## Diagnostics

Administrators can run the following Telegram command:

```text
/debug
```

The report is admin-only and intentionally excludes secrets. It checks the BlueBot version, Mini App version, PHP runtime, database connectivity, writable storage, Composer vendor availability, webhook protection, dedicated API-token configuration, leftover installer files, payment delivery errors and free disk space.

The same health information is available in the Web admin panel: **Diagnostics**.

Payment records that are financially confirmed but fail during service delivery are marked as `delivery_error` so they remain visible for administrator review instead of being silently treated as fully delivered.

## Security notes

- Keep `config.php`, database credentials and Telegram tokens out of public commits.
- Use HTTPS for bot webhooks and payment callbacks.
- Keep the operating system, PHP and Composer dependencies updated.
- Review admin access and backup permissions before production deployment.
- Test payment callbacks and VPN-panel credentials in a staging environment when possible.

## Development direction

BlueBot is being progressively separated from its upstream identity and modernized around its own branding, release pipeline, installer, documentation and maintainable architecture. Existing installations are kept compatible while the internal structure is refactored incrementally.

## License and upstream attribution

BlueBot is distributed under **AGPL-3.0-or-later**, matching the license of the codebase it was derived from.

This repository is based on and contains work originating from **Mirza Bot / mahdiMGF2**. The original license and attribution are intentionally preserved. BlueBot's branding, maintenance, packaging and subsequent modifications are maintained in this repository.

See [LICENSE](LICENSE) for the full license text.

---

<div align="center">

**BlueBot** · maintained at **hazhanhasani/bluebot**

</div>
