# BlueBot Project Structure

This document defines the intended repository layout and keeps runtime code, integrations, documentation, generated assets, and operational files separated.

## Root

The repository root is reserved for application entry points and project metadata:

- `index.php` — Telegram webhook entry point
- `admin.php` — Telegram administrator workflow
- `function.php` — legacy shared application functions
- `keyboard.php` — Telegram keyboards
- `panels.php` — panel lifecycle orchestrator
- `request.php` — shared HTTP client
- `botapi.php` — Telegram Bot API helper
- `config.php` — installation-time configuration template
- `install.sh` — installer and updater CLI
- `composer.json`, `composer.lock`, `version` — package/release metadata
- `README.md`, `NOTICE.md`, `LICENSE` — project documentation and licensing

External panel implementations do **not** belong in the repository root.

## Application modules

```text
src/
├── Panel/
│   └── Adapters/
│       ├── Marzban.php
│       ├── Marzneshin.php
│       ├── SolidLayer.php
│       ├── ThreeXUI.php
│       ├── AlirezaXUI.php
│       ├── Hiddify.php
│       ├── SUI.php
│       ├── WGDashboard.php
│       ├── MikroTik.php
│       ├── IBSng.php
│       ├── MirzaAgent.php
│       └── Rebecca.php
├── Payment/
└── Support/
    ├── Diagnostics.php
    ├── InstallerGuard.php
    ├── JalaliDate.php
    └── Logger.php
```

`panels.php` is the only root-level orchestrator for panel adapters. New external panel integrations should be added to `src/Panel/Adapters/`.

## Assets and runtime state

- `assets/images/qr-background.jpg` — tracked default QR background
- `storage/qr/background.jpg` — administrator-defined runtime QR background; untracked
- Runtime-generated files belong under `storage/`, never in the repository root.

## Runtime and web surfaces

- `api/` — application/API endpoints
- `app/` — Telegram Mini App build
- `panel/` — Web Admin
- `payment/` — payment callbacks and gateway endpoints
- `cronbot/` — scheduled jobs and dispatcher
- `sub/` — subscription-facing endpoints
- `vpnbot/` — legacy/runtime VPN bot surface

## Data and operations

- `db/` — schema, migrations and table definitions
- `install/` — installer support files
- `storage/` — runtime cache/log/state files
- `tests/` — repository and compatibility contract checks
- `docs/` — maintained source documentation
- `docs/generated/` — generated documentation output; ignored by Git

## Naming rules

- PHP integration adapters use PascalCase filenames.
- Scheduled job filenames use descriptive English names.
- New reusable runtime code belongs under `src/`, not in the repository root.
- Generated files, logs, temporary archives and local environment files must remain untracked.
