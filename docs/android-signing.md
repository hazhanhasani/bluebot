# Blue VPN Android Release Signing

Official Blue VPN APKs use one permanent release certificate.

## GitHub Actions secrets

Configure these repository secrets before publishing the first permanently
signed release:

- `ANDROID_KEYSTORE_BASE64`
- `ANDROID_KEYSTORE_PASSWORD`
- `ANDROID_KEY_ALIAS`
- `ANDROID_KEY_PASSWORD`

The private keystore and passwords must never be committed to Git.

## Pinned certificate

Alias:

```text
blue-vpn-release
```

SHA-1:

```text
D8:65:36:85:3E:C3:7E:6B:EF:7D:08:AF:CF:21:6E:F9:6E:31:49:B4
```

SHA-256:

```text
5A:C9:01:35:6D:B4:F7:9B:E2:CB:F5:77:E3:74:39:A0:41:4C:88:1D:A2:00:B4:23:A5:9F:26:F9:EB:4E:9A:CC
```

The stable-release workflow verifies the generated APK with `apksigner` and
fails before publication if this certificate changes.

## Migration

Android builds published before permanent signing used ephemeral debug keys.
Users of those builds must uninstall the old app once before installing the
first permanently signed Blue VPN APK. Keep the permanent keystore backed up
securely; losing it would require another package-signature migration.
