# Blue VPN Android Client Architecture

## Goal

Provide a first-party Android VPN client for BlueBot customers. The customer authenticates with a BlueBot-generated username/password and never needs to manually copy a subscription link into a third-party client.

## Trust boundaries

- BlueBot owns customer identity, billing and subscription authorization.
- The Android app receives a device-bound bearer session after password authentication.
- `/api/client.php` returns only subscriptions owned by the authenticated BlueBot user.
- Panel administrator credentials remain server-side.
- Connection profiles are transport secrets and must be sent only over HTTPS.

## Authentication

`AppClientAuth` maintains three tables:

- `app_client_accounts`: one app identity per purchased invoice/service; password stored as a one-way hash.
- `app_client_sessions`: SHA-256 token hashes, device binding, expiry and revocation.
- `app_client_login_guards`: failed-login throttling.

A password rotation revokes all existing client sessions.

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

Returns exactly the invoice/service bound to the authenticated app account and whether its panel type is supported by the Android client.

### `GET /api/client.php?action=service&id=<invoice-id>`

Returns traffic/expiry metadata plus an in-memory Xray connection source. Ownership is checked again by `id_user` and `id_invoice` before any profile is returned.

### `POST /api/client.php?action=logout`

Revokes the current bearer session.

## VPN engine

The app establishes Android `VpnService`, obtains the system TUN fd, then passes that fd to Xray-core through `xray.tun.fd`. `libXray` socket protection is registered so Xray's upstream sockets stay outside the Android VPN route and do not loop back into the tunnel.

## Next production milestone

- permanently signed release APK/AAB pipeline using GitHub Actions secrets and a pinned certificate fingerprint
- remote minimum-version / forced-update policy
- device/session list and revoke controls
- latency testing and automatic node selection
- reconnect on network transitions
- per-app routing and split tunneling
- crash telemetry with secret redaction
- protocol fixture tests against every enabled BlueBot panel adapter
