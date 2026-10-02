# Blue VPN Android Client Architecture

## Goal

Provide a first-party Android client for BlueBot customers. Customers authenticate
with service-specific BlueBot credentials and never need to manually copy a
subscription URL into another application.

## Trust boundaries

- BlueBot owns customer identity, billing and service authorization.
- Each `app_client_accounts` row is bound to one BlueBot invoice through `invoice_id`.
- A bearer session can read only its bound invoice/service.
- Cross-service API requests are rejected even when the same Telegram user owns both services.
- Panel administrator credentials remain server-side.
- Connection profiles are returned only over HTTPS.

## Authentication

`AppClientAuth` maintains:

- `app_client_accounts`: service-scoped app identities with one-way password hashes.
- `app_client_sessions`: SHA-256 token hashes, device binding, expiry and revocation.
- `app_client_login_guards`: failed-login throttling.

Legacy user-wide app accounts are disabled by the service-scope migration.
Rotating a password revokes sessions for that app account/service.

## API

### `POST /api/client.php?action=login`

```json
{
  "username": "bp-...",
  "password": "...",
  "device_id": "installation-id"
}
```

### `GET /api/client.php?action=services`

Returns a one-item service collection containing only the invoice bound to the
authenticated app account.

### `GET /api/client.php?action=service&id=<invoice-id>`

Returns traffic/expiry metadata and the in-memory Xray connection source only
when the requested ID matches the authenticated account's invoice.

### `GET /api/client.php?action=app-version`

Returns the currently published Android version, minimum version, release notes
and HTTPS download URL.

### `POST /api/client.php?action=logout`

Revokes the current bearer session.

## VPN engine

Blue VPN establishes Android `VpnService`, obtains the TUN file descriptor and
passes it to Xray-core through `xray.tun.fd`. libXray socket protection keeps
upstream sockets outside the Android VPN route and prevents routing loops.

## Release identity and signing

The Android application ID remains `com.bluepanel.client`. Official release
APKs are built with one permanent Blue VPN signing key. GitHub Actions refuses
to publish a release APK if the signing secrets are absent or if the produced
certificate does not match the pinned public SHA-256 fingerprint.

The 0.6.1/0.6.2 Android artifacts used temporary debug keys. Moving to the first
permanently signed APK therefore requires a one-time uninstall of those old
debug-signed builds. All releases after that first migration can update in place
as long as the permanent key is preserved.

See `docs/android-signing.md`.
