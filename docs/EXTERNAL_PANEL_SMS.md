# External panel SMS sync

BlueBot can detect users that are created or renewed **directly in a connected PasarGuard/Marzban panel**, even when no BlueBot invoice exists for that user.

## How to use

1. Enable SMS and configure the `service_activated` and `service_renewed` patterns in **Panel > SMS**.
2. Keep the main BlueBot cron dispatcher running every minute.
3. When creating or editing a user directly in PasarGuard/Marzban, put the customer's Iranian mobile number anywhere in the user **Note / Remark** field, for example:

```text
09121234567
```

or:

```text
Customer: Ali | 09121234567
```

The watcher normalizes `09...`, `98...`, `0098...`, and `+98...` formats automatically.

## Events

- New panel user created recently -> `service_activated`
- Expiry increased -> `service_renewed`
- Data limit increased -> `service_renewed`
- Traffic reset detected -> `service_renewed`
- Disabled/expired user becomes active -> `service_renewed`
- A phone number is added later to an active manual user -> `service_activated`

## Safety

The first discovery of old users is stored as a baseline, so enabling this feature does **not** send an activation SMS to every existing panel user. State is kept in `external_panel_sms_state`, and SMS delivery keeps using BlueBot's existing deduplication and retry queue.

## Performance

For current PasarGuard releases, the watcher polls only the most recently created and edited users (`created_at` / `edit_at`) instead of downloading the complete user database every minute. Older Marzban-compatible APIs fall back to the legacy list endpoint.
