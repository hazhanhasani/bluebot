# Blue VPN Android Client

First-party Android client for BlueBot customers. Each app username/password is bound to exactly one purchased service, so the customer signs in without copying subscription links into third-party applications.

## Stack

- Kotlin + Jetpack Compose
- Android `VpnService`
- Xray-core through the official `XTLS/libXray` Android AAR
- BlueBot Client API (`/api/client.php`)
- Android Keystore for bearer-token encryption at rest
- Permanent release signing through GitHub Actions secrets

## User flow

1. The customer sends `/app` to BlueBot.
2. The customer selects one purchased subscription.
3. BlueBot generates credentials for that subscription only.
4. The customer signs in to Blue VPN.
5. Blue VPN retrieves only the service bound to those credentials.
6. Available Xray locations are rendered as a swipeable arc of country flags.
7. The customer selects a location and taps **Connect**; only that route is handed to the in-memory Xray engine.

The UI never displays subscription URLs or share links. Location labels are derived in memory from the active service profile and are not persisted.

## API endpoint

The default API endpoint is:

```text
https://bot.blluepanel.ir/api/client.php
```

Override it for a debug build:

```bash
gradle -p android-client :app:assembleDebug \
  -PBLUEBOT_API_BASE=https://example.com/api/client.php
```

## Build

Requirements:

- JDK 17
- Gradle 9.6+
- Android SDK 37

Debug:

```bash
gradle -p android-client :app:assembleDebug
```

Release builds require the permanent signing environment variables documented in
`docs/android-signing.md`. A release build intentionally fails when signing is
not configured; official APKs must never fall back to the Android debug key.

The build downloads `libxray-android.zip` from the pinned official release,
verifies its SHA-256 digest and extracts the AAR locally. The binary is not
committed to this repository.

## Security

- Passwords are stored server-side only as one-way hashes.
- One credential maps to one BlueBot invoice/service.
- Sessions are device-bound and revocable.
- Bearer tokens are encrypted with Android Keystore.
- Cleartext HTTP is disabled.
- Subscription payloads remain in memory.
- Panel administrator credentials are never returned to Android.
- Official APK releases are verified against a pinned signing-certificate SHA-256.
- Stable APKs are signed with v1, v2 and v3 schemes and checked with `zipalign -P 16`.
- Releases up to BlueBot v0.6.2 were CI debug-signed. Android cannot update those installs with the permanent production key introduced in v0.6.3, so those legacy builds require a one-time uninstall/reinstall migration.

## Branding

The launcher name is **Blue VPN** and the launcher/application icon is stored at
`app/src/main/res/drawable-nodpi/blue_vpn_icon.png`.

Internal package and class names remain `com.bluepanel.client` / `BluePanel*`
to keep Android package identity and installed-app continuity stable.
