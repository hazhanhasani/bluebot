# FarazSMS patterns for BlueBot

BlueBot uses the official FarazSMS / IranPayamak REST API.

- API base: `https://api.iranpayamak.com/ws/v1`
- Authentication header: `Api-Key`
- Pattern send: `POST /sms/pattern`
- Active patterns: `GET /patterns`
- Accessible sender lines: `GET /lines/accessible`

BlueBot discovers accessible sender lines automatically, walks all pattern pages, keeps only active patterns, and maps compatible patterns to events by exact variable name/type plus message semantics.

## Pattern texts to create in FarazSMS

Create the following patterns in FarazSMS. Variable names are case-sensitive and must match exactly. Preserve the shown line breaks as real new lines; do not type the two characters `\\n`.

| Event | Suggested category | Exact pattern text | Variables |
| --- | --- | --- | --- |
| `phone_verification` | OTP | `بلو پنل`<br>`کد ورود: %code%`<br>`این کد محرمانه است.` | `code`: number, max 6 |
| `service_activated` | Others | `بلو پنل`<br>`سرویس %service% فعال شد.`<br>`کاربر: %username%`<br>`اعتبار: %expire_date%` | `service`: string, max 40; `username`: string, max 40; `expire_date`: string, max 20 |
| `service_renewed` | Others | `بلو پنل`<br>`سرویس %username% تمدید شد.`<br>`اعتبار جدید: %expire_date%` | `username`: string, max 40; `expire_date`: string, max 20 |
| `subscription_reminder` | Others | `بلو پنل`<br>`%days_left% روز تا پایان سرویس %username% باقی مانده.`<br>`برای جلوگیری از قطعی، تمدید کنید.` | `days_left`: number, max 3; `username`: string, max 40 |
| `subscription_expired` | Others | `بلو پنل`<br>`اعتبار سرویس %username% تمام شد.`<br>`برای اتصال مجدد، تمدید کنید.` | `username`: string, max 40 |
| `low_remaining_volume` | Others | `بلو پنل`<br>`حجم سرویس %username% رو به پایان است.`<br>`باقی‌مانده: %remaining_volume% گیگ` | `username`: string, max 40; `remaining_volume`: string, max 12 |
| `volume_expired` | Others | `بلو پنل`<br>`حجم سرویس %username% تمام شد.`<br>`برای ادامه، سرویس را تمدید کنید.` | `username`: string, max 40 |
| `payment_success` | Order | `بلو پنل`<br>`پرداخت %amount% تومان موفق بود.`<br>`کد سفارش: %order_id%` | `amount`: number, max 12; `order_id`: string, max 40 |
| `payment_failed` | Order | `بلو پنل`<br>`پرداخت سفارش %order_id% ناموفق بود.`<br>`لطفاً دوباره تلاش کنید.` | `order_id`: string, max 40 |
| `wallet_charged` | Others | `بلو پنل`<br>`%amount% تومان به کیف پول اضافه شد.`<br>`موجودی: %balance% تومان` | `amount`: number, max 12; `balance`: number, max 12 |
| `admin_announcement` | Others | `بلو پنل`<br>`%message%` | `message`: string, max 120 |

### Variable rules

- Use **number / عدد** for numeric variables.
- Use **string / رشته** for every non-numeric variable.
- BlueBot exposes and stores only these two canonical variable types: `number` and `string`.
- Do not rename variables. For example, `%code%` in FarazSMS must remain exactly `code`.
- `remaining_volume` is text because values may contain decimals such as `1.5`.
- `order_id` is text because payment providers may use non-numeric identifiers.
- `expire_date` is text because BlueBot can send Jalali-formatted dates.

## OTP flow

When both the SMS system and OTP verification are enabled in **Panel → SMS & Notifications**:

1. Telegram asks the user to share their own contact.
2. BlueBot normalizes the Iranian mobile number.
3. A cryptographically random six-digit code is generated.
4. The code is stored only as an HMAC hash in `sms_otp_challenges`.
5. The `phone_verification` FarazSMS pattern is sent.
6. The phone number is saved to the user only after a correct, non-expired code.
7. Resend delay, expiry, and maximum attempts are configurable from the web panel.

## Automatic discovery

### Sender line

BlueBot calls `GET /lines/accessible`, filters usable lines, prefers dedicated lines, caches the result, and persists the selected line as a resilience fallback. The sender line is not manually editable.

### Patterns

BlueBot requests pattern pages until the provider reports the last page, returns an empty page, or repeats a page. Inactive patterns are discarded. A high safety guard prevents silently accepting an incomplete result.

For each SMS event, BlueBot validates:

1. exact variable names;
2. `number` vs `string` variable types;
3. semantic similarity of the FarazSMS pattern text/description.

If an assigned pattern becomes inactive or incompatible, BlueBot attempts automatic rediscovery before queuing and again before dispatch.
