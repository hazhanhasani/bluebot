# Panel Compatibility Matrix

This document records the upstream API/release baseline that BlueBot panel adapters are maintained against.

Last reviewed: 2026-10-01

| BlueBot type | Upstream baseline | Adapter | Notes |
|---|---|---|---|
| `marzban` | Marzban v0.8.4 | `src/Panel/Adapters/Marzban.php` | Handles the v0.8.4 delete-after-success HTTP 500 edge case by verifying deletion. |
| `marzban` + `version_panel=1` | PasarGuard v5.4.1 | `src/Panel/Adapters/Marzban.php` | Uses Marzban-compatible token/user API with `group_ids` and `proxy_settings`. |
| `marzneshin` | Marzneshin v0.7.4 | `src/Panel/Adapters/Marzneshin.php` | Uses required `expire_strategy`, ISO datetimes and current service IDs. |
| `solidlayer` | GoGuard API / Swagger 1.0 (2026-09-26) | `src/Panel/Adapters/SolidLayer.php` | Native `X-API-Key` subscription API integration. |
| `x-ui_single` | 3x-ui v3.8.5 | `src/Panel/Adapters/ThreeXUI.php` | Full client replacement updates, `keepTraffic` delete option and JSON `inboundIds`. |
| `alireza_single` | alireza0/x-ui v1.12.0 | `src/Panel/Adapters/AlirezaXUI.php` | Protocol-aware client identifier: id/password/email. |
| `hiddify` | Hiddify Manager v13.0.3 stable | `src/Panel/Adapters/Hiddify.php` | v2 user API with `Hiddify-API-Key`; typed v13 payloads, validation-aware errors, real usage reset, and legacy Basic fallback. |
| `s_ui` | S-UI v1.6.3 | `src/Panel/Adapters/SUI.php` | `/apiv2` token API and current `save` object contract. |
| `WGDashboard` | WGDashboard v4.3.3 | `src/Panel/Adapters/WGDashboard.php` | Current v4 peer, traffic and schedule APIs. |
| `mikrotik` | RouterOS v7 REST API | `src/Panel/Adapters/MikroTik.php` | Native REST CRUD with compatibility fallbacks for older v7 builds. |
| `ibsng` | Legacy IBSng web API | `src/Panel/Adapters/IBSng.php` | Bundled radiusApi adapter; no authoritative modern public API release baseline. |
| `mirza_agent` | Current Mirza Agent protocol | `src/Panel/Adapters/MirzaAgent.php` | Matches current upstream adapter. |
| `rebecca` | Current Rebecca custom API | `src/Panel/Adapters/Rebecca.php` | Matches current upstream adapter. |
| `Manualsale` | BlueBot internal | `panels.php` | No external API dependency. |

## Compatibility policy

- Stable upstream releases are the default target. Pre-releases are not adopted unless explicitly required.
- BlueBot keeps backwards-compatible fallbacks when the upstream transition can be detected safely.
- External APIs are treated as untrusted input: dynamic path components are URL-encoded and responses are validated before use.
- API keys, tokens and passwords must never be logged.
- A successful HTTP status is not assumed to mean a successful operation when the upstream API uses an application-level success flag.
- Known upstream bugs are handled only when BlueBot can verify the resulting state safely.

## Hiddify v13 notes

- Hiddify Manager v13.0.3 keeps the v2 admin user endpoints used by BlueBot, but the request/response schemas are now Pydantic-based and should be treated as typed input.
- BlueBot sends integer `package_days`, numeric usage fields, accepts the complete HTTP 2xx success range, and surfaces v13 validation details instead of returning only an HTTP code.
- Hiddify v13 calls its upstream `quick_apply_users()` flow after user create, patch, and delete operations. The stale sing-box user limitation documented for v12.3.3 is therefore retained only as a legacy-v12 operational note.
- User subscription roots remain compatible with `/<proxy_path>/<uuid>/`; v13 also exposes `/auto`, `/sub`, `/sub64`, `/xray`, `/singbox`, and Clash endpoints below the same user route.
