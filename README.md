<div align="center">

# 🔵 BlueBot

### پلتفرم متن‌باز فروش، مدیریت و اتوماسیون سرویس‌های VPN در تلگرام

<p>
  <strong>Telegram Bot • Web Admin • Mini App • Multi-Panel • Payments • Automation</strong>
</p>

[![BlueBot CI](https://github.com/hazhanhasani/bluebot/actions/workflows/ci.yml/badge.svg)](https://github.com/hazhanhasani/bluebot/actions/workflows/ci.yml)
[![Version](https://img.shields.io/badge/version-0.5.17-0A84FF?style=flat-square)](version)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-AGPL--3.0-blue?style=flat-square)](LICENSE)
[![GitHub](https://img.shields.io/badge/GitHub-hazhanhasani%2Fbluebot-181717?style=flat-square&logo=github)](https://github.com/hazhanhasani/bluebot)

<p>
  <a href="#install">نصب سریع</a> •
  <a href="#features">امکانات</a> •
  <a href="#panels">پنل‌های پشتیبانی‌شده</a> •
  <a href="#payments">پرداخت</a> •
  <a href="#security">امنیت</a> •
  <a href="#structure">ساختار پروژه</a>
</p>

</div>

---

<a id="overview"></a>
<h2 dir="rtl" align="right">🚀 BlueBot چیست؟</h2>

<p dir="rtl" align="right">
<strong>BlueBot</strong> یک پلتفرم Self-Hosted برای فروش و مدیریت اشتراک‌های VPN از طریق تلگرام است.
این پروژه فرآیند کامل فروش سرویس را از انتخاب محصول و پرداخت تا ساخت اکانت، تحویل لینک اشتراک، تمدید، افزایش حجم، مدیریت کیف پول، اطلاع‌رسانی و مدیریت کاربران خودکار می‌کند.
</p>

<p dir="rtl" align="right">
BlueBot فقط یک ربات تلگرام نیست؛ پروژه شامل <strong>ربات فروش</strong>، <strong>پنل مدیریت وب</strong>، <strong>Telegram Mini App</strong>، سیستم نصب و بروزرسانی، مهاجرت دیتابیس، اتصال به چندین پنل VPN، سیستم پرداخت، ابزارهای Diagnostics و زیرساخت CI/CD است.
</p>

> [!NOTE]
> BlueBot برای اجرا روی سرور شخصی طراحی شده است و کنترل داده‌ها، دیتابیس، توکن‌ها و اتصال به پنل‌های VPN در اختیار مدیر سرور باقی می‌ماند.

<a id="architecture"></a>
<h2 dir="rtl" align="right">🧩 معماری کلی</h2>

~~~mermaid
flowchart LR
    U["👤 کاربر"] --> T["🤖 ربات تلگرام"]
    U --> M["📱 Mini App"]
    T --> C["🔵 هسته BlueBot"]
    M --> C
    C --> A["🖥️ پنل مدیریت وب"]
    C --> P["💳 درگاه‌های پرداخت"]
    C --> V["🛡️ پنل‌های VPN"]
    C --> D[("🗄️ MySQL")]
    C --> J["⏱️ Cron / Automation"]
~~~

<a id="features"></a>
<h2 dir="rtl" align="right">✨ امکانات اصلی</h2>

| قابلیت | توضیح |
|---|---|
| 🤖 فروش خودکار | فروش سرویس VPN و تحویل خودکار کانفیگ یا لینک اشتراک |
| 🧪 اکانت تست | ایجاد سرویس آزمایشی با محدودیت زمان و حجم |
| ♻️ تمدید سرویس | تمدید زمان، حجم یا هر دو با روش‌های مختلف |
| 📦 حجم اضافه | فروش و اعمال حجم اضافه روی سرویس فعال |
| ⏳ زمان اضافه | افزایش زمان سرویس بدون نیاز به ساخت اکانت جدید |
| 💰 کیف پول | مدیریت موجودی، شارژ حساب و پرداخت از اعتبار |
| 👥 نمایندگی | امکانات مخصوص Agent / Reseller و قیمت‌گذاری متفاوت |
| 🎁 تخفیف و هدیه | کد تخفیف، Gift Code، Referral و Cashback |
| 📲 Mini App | رابط کاربری وب داخل تلگرام |
| 🖥️ Web Admin | مدیریت کاربران، سفارش‌ها، محصولات، پرداخت‌ها و تنظیمات |
| 🔗 Subscription | تحویل لینک اشتراک و Configهای قابل استفاده |
| 📷 QR Code | تولید QR برای دسترسی سریع کاربران |
| 🔔 اعلان‌ها | مدیریت هشدارها، وضعیت سرویس و رویدادهای مهم |
| 🗄️ Backup | پشتیبان‌گیری و ابزارهای نگهداری دیتابیس |
| ⏱️ Cron Jobs | اجرای خودکار وظایف دوره‌ای و بررسی سرویس‌ها |
| 🌐 چندزبانه | ساختار ترجمه در پوشه <code>lang/</code> |
| 🩺 Diagnostics | بررسی سلامت دیتابیس، PHP، Storage، Webhook و سرویس‌ها |
| 🧾 Audit | ثبت و مشاهده رویدادهای مدیریتی در پنل وب |
| 🔄 بروزرسانی | Installer/Updater داخلی با کانال Stable و Beta |
| ✅ CI/CD | بررسی Syntax، Composer، UI contracts و Release automation با GitHub Actions |

<a id="panels"></a>
<h2 dir="rtl" align="right">🛡️ پنل‌های پشتیبانی‌شده</h2>

<p dir="rtl" align="right">
BlueBot از چند Adapter مستقل برای اتصال به پنل‌های مختلف استفاده می‌کند. هر پنل بر اساس API و ساختار خودش مدیریت می‌شود.
</p>

| پنل | نسخه / API هدف | وضعیت اتصال | توضیح |
|---|---|---:|---|
| **Marzban** | **v0.8.4** | ✅ Native | ساخت، ویرایش، حذف، تمدید و مدیریت کاربران |
| **Marzneshin** | **v0.7.4** | ✅ Native | Schema جدید Expire Strategy و مدیریت سرویس‌ها |
| **PasarGuard** | **v5.4.1** | ✅ Compatible | API سازگار با Marzban، Group و Proxy Settings |
| **SolidLayer / GoGuard** | **Swagger API 1.0** | ✅ Native | اتصال مستقیم با <code>X-API-Key</code> و API اشتراک‌ها |
| **3x-ui / Sanaei** | **v3.8.5** | ✅ Native | Client API، Traffic، Attach و Delete جدید |
| **Alireza X-UI** | **v1.12.0** | ✅ Native | شناسه Client سازگار با VMess/VLESS/Trojan/SS |
| **Hiddify Manager** | **v12.3.3 stable** | ✅ Native | API v2 و <code>Hiddify-API-Key</code> |
| **S-UI** | **v1.6.3** | ✅ Native | <code>/apiv2</code> و قرارداد جدید Save |
| **WGDashboard** | **v4.3.3** | ✅ Native | WireGuard Peer API v4 |
| **MikroTik** | **RouterOS v7 REST** | ✅ Integrated | REST CRUD + User Manager |
| **IBSng** | **Legacy Web API** | ✅ Integrated | Adapter داخلی radiusApi |
| **Mirza Agent** | **Current upstream** | ✅ Integrated | اتصال به Agent API |
| **Rebecca** | **Current upstream** | ✅ Integrated | Adapter اختصاصی |
| **Manual Sale** | **Internal** | ✅ Built-in | فروش دستی کانفیگ‌های از پیش ثبت‌شده |

> جزئیات نسخه‌های هدف، محدودیت‌ها و سیاست سازگاری در [Panel Compatibility Matrix](docs/PANEL_COMPATIBILITY.md) نگهداری می‌شود.

<h3 dir="rtl" align="right">SolidLayer / GoGuard</h3>

<p dir="rtl" align="right">
اتصال SolidLayer / GoGuard به‌صورت مستقل داخل BlueBot پیاده‌سازی شده و از API Key استفاده می‌کند.
</p>

- <code>POST /api/subscriptions</code> — ساخت اشتراک
- <code>GET /api/subscriptions</code> — دریافت اطلاعات کاربر
- <code>PUT /api/subscriptions</code> — ویرایش حجم، زمان و سرویس
- <code>DELETE /api/subscriptions</code> — حذف اشتراک
- <code>/api/subscriptions/enable</code> و <code>/disable</code> — فعال/غیرفعال
- <code>/api/subscriptions/reset</code> — ریست مصرف
- <code>/api/subscriptions/revoke</code> — تغییر Access Key
- <code>/api/subscriptions/{username}/links</code> — دریافت لینک‌ها
- <code>/api/services</code> — دریافت سرویس‌ها
- <code>/api/stats/overview</code> — نمایش آمار پنل

<a id="provider-catalogs"></a>
<h2 dir="rtl" align="right">🧩 Provider Catalog Sync & Profit Pricing</h2>

<p dir="rtl" align="right">
BlueBot می‌تواند کاتالوگ JSON ارائه‌دهندگان خدمات را به‌صورت خودکار دریافت کند، محصولات را بر اساس دسته‌بندی Provider وارد ربات کند، قیمت فروش را از قیمت عمده + درصد سود بسازد و محصولات حذف‌شده از API را غیرفعال کند.
</p>

- هر Provider یک <code>Catalog URL</code>، mapping فیلدهای JSON، ارز عمده، نرخ تبدیل به تومان و درصد سود دارد.
- قیمت فروش با فرمول <code>wholesale × exchange rate × (1 + profit%)</code> محاسبه و به هزار تومان رو به بالا گرد می‌شود.
- دسته‌ها از فیلد category یا نام محصول تشخیص داده می‌شوند و به‌صورت داینامیک در «فروش خدمات» ظاهر می‌شوند.
- Sync دستی از پنل و Sync زمان‌بندی‌شده از Cron پشتیبانی می‌شود.
- برای TGTools، حاشیه سود <strong>Stars</strong> و <strong>Premium</strong> مستقل است و قیمت عمده از <code>/api/purchase/prices</code> خوانده می‌شود.
- Providerهای عمومی تا زمانی که delivery adapter اختصاصی نداشته باشند، پس از تأیید ادمین با حالت تحویل دستی ثبت می‌شوند؛ Providerهای یکپارچه مثل TGTools، OZVinoo و TivaNovin از مسیر اختصاصی خودشان استفاده می‌کنند.
- TivaNovin از قرارداد SMM با درخواست‌های POST پشتیبانی می‌شود: `services`، `add`، `status` و `balance`. نرخ‌های IRR به تومان تبدیل، درصد سود Provider اعمال و وضعیت سفارش‌ها با Cron پیگیری می‌شود.
- Endpoint فعلی TivaNovin طبق مستندات Provider روی HTTP است؛ BlueBot این استثنا را فقط به دامنه `tivanovin.ir` و مسیر `/api` محدود می‌کند و برای سایر Providerها HTTPS اجباری باقی می‌ماند.
- OZVinoo با Deep Discovery بررسی می‌شود: REST GET و SMM `action=services`، روش‌های `Bearer`، `X-API-Key`، `Api-Key` و body-only، و ساختارهای تو‌در‌توی JSON به‌صورت خودکار بررسی می‌شوند.
- دسته‌های عمومی عضوینو مثل Telegram، Instagram، YouTube، X/Twitter، TikTok، Spotify، LinkedIn، Facebook، WhatsApp، Likee و Naver TV به دسته‌های مرتب فروش خدمات نگاشت می‌شوند.

<a id="digital-services"></a>
<h2 dir="rtl" align="right">⭐ فروش Telegram Stars و Premium</h2>

<p dir="rtl" align="right">
بخش «فروش خدمات» از Provider <strong>TGTools</strong> برای Telegram Stars و Telegram Premium پشتیبانی می‌کند. سفارش از کیف پول داخلی BlueBot ثبت می‌شود و فقط بعد از تأیید دستی ادمین به Provider فرستاده می‌شود.
</p>

- <code>GET /api/purchase/prices</code> — دریافت قیمت‌های زنده و بسته‌های Stars/Premium از TGTools
- <code>POST /api/purchase/stars</code> — ارسال Stars بر اساس username و مقدار
- <code>POST /api/purchase/premium</code> — ارسال Premium برای ۳، ۶ یا ۱۲ ماه
- <code>GET /api/purchase/{transactionId}</code> — پیگیری وضعیت سفارش Provider
- <code>trackingCode</code> — استفاده از کد سفارش BlueBot برای جلوگیری از ارسال تکراری
- برای TGTools هیچ <code>Provider Service Code</code> لازم نیست؛ BlueBot کد داخلی را خودش از نوع و مقدار محصول می‌سازد.
- بسته‌های Stars از پاسخ زنده TGTools تشخیص داده می‌شوند؛ اگر endpoint موقتاً در دسترس نباشد، BlueBot یک کاتالوگ پایه و غیرفعال می‌سازد.
- محصولات تازه با قیمت فروش صفر و حالت غیرفعال ساخته می‌شوند؛ مدیر قیمت تومان را تعیین می‌کند تا محصول فعال شود.
- Cron هر ۱۵ دقیقه کاتالوگ TGTools را تازه می‌کند و سفارش‌های درحال‌پردازش را نیز پیگیری می‌کند.

> [!IMPORTANT]
> مقصد TGTools برای Stars/Premium باید username معتبر تلگرام باشد. API Key فقط در تنظیمات امن پنل ذخیره می‌شود و به کاربر نمایش داده نمی‌شود.

<a id="payments"></a>
<h2 dir="rtl" align="right">💳 سیستم پرداخت</h2>

<p dir="rtl" align="right">
BlueBot از پرداخت دستی و چندین Gateway آنلاین پشتیبانی می‌کند. Callback و Webhook هر درگاه در پوشه <code>payment/</code> نگهداری می‌شود.
</p>

| درگاه / روش | وضعیت |
|---|---:|
| کارت به کارت / تایید دستی | ✅ |
| Zarinpal | ✅ |
| AqayePardakht | ✅ |
| NowPayments | ✅ |
| IranPay 1 / 2 / 4 | ✅ |
| Variza | ✅ |
| Blupal | ✅ |
| Webhook-based payment flows | ✅ |

> **Blupal:** در پنل بلوپال هر دو آدرس باید ثبت شوند: `/payment/blupal_callback.php` به‌عنوان Callback و `/payment/blupal_webhook.php` به‌عنوان Webhook.

> [!IMPORTANT]
> قبل از استفاده در محیط Production، Callback URL، Webhook Secret و تنظیمات هر درگاه را با حساب واقعی خودتان بررسی کنید.

<a id="requirements"></a>
<h2 dir="rtl" align="right">⚙️ پیش‌نیازها</h2>

| مورد | مقدار پیشنهادی |
|---|---|
| سیستم‌عامل | Ubuntu 22.04 / 24.04 / 26.04 |
| دسترسی | Root |
| PHP | 8.2 یا جدیدتر |
| Database | MySQL |
| Web Server | Apache |
| Dependency Manager | Composer |
| Domain | دامنه متصل به IP سرور |
| SSL | HTTPS معتبر برای Production |

> [!WARNING]
> Installer برای نصب جدید، یک **سرور تمیز** را توصیه می‌کند. وجود Web Server، دیتابیس یا پنل‌های دیگر روی سرور ممکن است با نصب خودکار تداخل ایجاد کند.

<a id="install"></a>
<h2 dir="rtl" align="right">⚡ نصب سریع</h2>

<p dir="rtl" align="right">
روی یک Ubuntu تازه، با کاربر <strong>root</strong> دستورات زیر را اجرا کنید:
</p>

~~~bash
curl -o install.sh -L https://raw.githubusercontent.com/hazhanhasani/bluebot/main/install.sh
bash install.sh
~~~

<p dir="rtl" align="right">
Installer وابستگی‌ها، PHP، MySQL، Apache، Composer و فایل‌های مورد نیاز BlueBot را آماده می‌کند.
</p>

<h3 dir="rtl" align="right">دستور مدیریت</h3>

~~~bash
bluebot
~~~

<p dir="rtl" align="right">
برای مشاهده تمام دستورات:
</p>

~~~bash
bluebot --help
~~~

<h3 dir="rtl" align="right">نصب غیرتعاملی با کانال مشخص</h3>

~~~bash
bluebot install --channel auto
~~~

<a id="update"></a>
<h2 dir="rtl" align="right">🔄 بروزرسانی</h2>

<p dir="rtl" align="right">
BlueBot دارای Updater داخلی است و می‌تواند از Release یا شاخه اصلی پروژه بروزرسانی شود.
</p>

**نسخه پایدار / Release:**

~~~bash
bluebot update --channel release
~~~

**نسخه Beta / آخرین تغییرات main:**

~~~bash
bluebot update --channel beta
~~~

**نسخه مشخص:**

~~~bash
bluebot update --version 0.5.8
~~~

> [!NOTE]
> کانال <code>release</code> از آخرین Tag منتشرشده استفاده می‌کند. اگر Release قابل دریافت نباشد، Installer می‌تواند طبق منطق داخلی خود به منبع جایگزین برگردد.

<a id="admin"></a>
<h2 dir="rtl" align="right">🖥️ مدیریت سیستم</h2>

### Web Admin

<p dir="rtl" align="right">
پنل مدیریت وب در مسیر <code>panel/</code> قرار دارد و بخش‌های اصلی مدیریت BlueBot را پوشش می‌دهد:
</p>

- کاربران
- سرویس‌ها و محصولات
- سفارش‌ها و Invoiceها
- درگاه‌های پرداخت
- تنظیمات ربات
- متن‌ها و Keyboardها
- Diagnostics
- Audit Log

### Telegram Admin

<p dir="rtl" align="right">
بخش بزرگی از مدیریت از داخل خود ربات تلگرام نیز در دسترس مدیران قرار دارد؛ از جمله افزودن پنل، مدیریت سرویس‌ها، تغییر قیمت، ساخت اکانت و تنظیم امکانات فروش.
</p>

<a id="diagnostics"></a>
<h2 dir="rtl" align="right">🩺 Diagnostics و Debug</h2>

<p dir="rtl" align="right">
مدیر ربات می‌تواند دستور زیر را در تلگرام اجرا کند:
</p>

~~~text
/debug
~~~

<p dir="rtl" align="right">
گزارش Debug برای مدیر ساخته می‌شود و اطلاعات حساس را عمداً نمایش نمی‌دهد. مواردی مانند وضعیت دیتابیس، نسخه PHP، فضای دیسک، Writable Storage، Composer Vendor، Webhook protection، API token و خطاهای Delivery بررسی می‌شوند.
</p>

<p dir="rtl" align="right">
همین اطلاعات در صفحه <strong>Diagnostics</strong> پنل مدیریت وب نیز قابل مشاهده است.
</p>

<a id="security"></a>
<h2 dir="rtl" align="right">🔐 امنیت</h2>

- فایل <code>config.php</code>، Token ربات، اطلاعات دیتابیس و API Key پنل‌ها را در Commit عمومی قرار ندهید.
- برای Webhook تلگرام و Callback درگاه‌ها از HTTPS معتبر استفاده کنید.
- دسترسی مدیران و Agentها را به‌صورت دوره‌ای بررسی کنید.
- قبل از بروزرسانی‌های بزرگ Backup دیتابیس تهیه کنید.
- PHP، Composer و Packageهای سیستم‌عامل را بروزرسانی نگه دارید.
- Credential پنل‌های VPN را فقط داخل تنظیمات امن BlueBot نگهداری کنید.
- برای بررسی سخت‌گیرانه Certificate پنل‌های خارجی می‌توانید متغیر محیطی زیر را فعال کنید:

~~~bash
export BLUEBOT_VERIFY_PANEL_TLS=1
~~~

> [!CAUTION]
> غیرفعال‌کردن بررسی TLS فقط برای سازگاری با برخی پنل‌های قدیمی یا Self-Signed در نظر گرفته شده است. برای محیط Production استفاده از Certificate معتبر توصیه می‌شود.

<a id="structure"></a>
<h2 dir="rtl" align="right">📁 ساختار پروژه</h2>

~~~text
bluebot/
├── index.php               # Telegram bot entry point
├── admin.php               # Telegram admin flows
├── function.php            # Shared business/application helpers
├── keyboard.php            # Telegram keyboards and menus
├── panels.php              # Unified VPN panel lifecycle manager
├── src/
│   ├── Panel/
│   │   └── Adapters/       # All external VPN panel integrations
│   ├── Payment/            # Payment domain helpers
│   └── Support/            # Logging, diagnostics and runtime support
├── assets/                 # Static project assets and defaults
├── app/                    # Telegram Mini App
├── panel/                  # Web administration panel
├── api/                    # Internal/API endpoints
├── db/                     # Schema, migrations and bootstrap
├── payment/                # Payment gateways and callbacks
├── cronbot/                # Scheduled jobs
├── lang/                   # Language files
├── src/                    # Modular application/support code
├── storage/                # Runtime storage and logs
├── install/                # Installer resources
├── install.sh              # Installer / updater CLI
└── .github/workflows/      # CI and release automation
~~~

<a id="workflow"></a>
<h2 dir="rtl" align="right">🧠 جریان فروش سرویس</h2>

~~~text
Customer
   │
   ▼
Telegram Bot / Mini App
   │
   ├── Select Product
   ├── Apply Discount
   ├── Payment / Wallet
   │
   ▼
Payment Verification
   │
   ▼
ManagePanel
   │
   ├── Marzban
   ├── Marzneshin
   ├── SolidLayer / GoGuard
   ├── X-UI
   ├── Hiddify
   └── Other adapters
   │
   ▼
Subscription / Config Delivery
   │
   ▼
Renewal • Extra Volume • Notifications • Support
~~~

<a id="database"></a>
<h2 dir="rtl" align="right">🗄️ دیتابیس و Migration</h2>

<p dir="rtl" align="right">
Schema دیتابیس به‌صورت ماژولار داخل <code>db/</code> مدیریت می‌شود. BlueBot هنگام نصب و بروزرسانی می‌تواند ساختار دیتابیس و ستون‌های مورد نیاز نسخه‌های جدید را آماده کند.
</p>

<p dir="rtl" align="right">
برای جلوگیری از از دست رفتن اطلاعات، قبل از Migration یا تغییرات مهم نسخه حتماً Backup تهیه کنید.
</p>

<a id="development"></a>
<h2 dir="rtl" align="right">🧪 توسعه و کنترل کیفیت</h2>

<p dir="rtl" align="right">
Workflow اصلی CI در <code>.github/workflows/ci.yml</code> اجرا می‌شود و بخش‌های مهم پروژه را بررسی می‌کند.
</p>

- Composer metadata validation
- نصب Dependencyها
- PHP syntax lint
- JavaScript lint
- UI contract checks
- Panel integrity checks
- Security / safety contracts
- Diagnostics tests
- Installer syntax validation
- Payment callback contracts

<p dir="rtl" align="right">
برای تغییرات مهم، توسعه روی Branch جدا و Merge از طریق Pull Request توصیه می‌شود.
</p>

<a id="troubleshooting"></a>
<h2 dir="rtl" align="right">🛠️ عیب‌یابی سریع</h2>

| مشکل | بررسی پیشنهادی |
|---|---|
| ربات پاسخ نمی‌دهد | Webhook، Token و وضعیت PHP/Apache |
| ساخت سرویس خطا دارد | URL، Credential/API Key و دسترسی پنل VPN |
| پرداخت تایید نمی‌شود | Callback URL، Webhook Secret و لاگ درگاه |
| بروزرسانی ناقص است | اجرای مجدد <code>bluebot update</code> و بررسی اینترنت سرور |
| پنل وب باز نمی‌شود | Apache، Domain، SSL و Permission فایل‌ها |
| خطای نامشخص | اجرای <code>/debug</code> یا صفحه Diagnostics |

<a id="license"></a>
<h2 dir="rtl" align="right">📜 مجوز و Attribution</h2>

<p dir="rtl" align="right">
BlueBot تحت مجوز <strong>GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later)</strong> منتشر می‌شود.
</p>

<p dir="rtl" align="right">
این پروژه بر پایه کدی توسعه یافته که بخشی از آن از پروژه <strong>Mirza Bot</strong> متعلق به <strong>mahdiMGF2</strong> منشأ گرفته است. مجوز اصلی و Attribution پروژه بالادستی عمداً حفظ شده‌اند.
</p>

- [LICENSE](LICENSE)
- [NOTICE.md](NOTICE.md)
- [Upstream: mahdiMGF2/mirzabot](https://github.com/mahdiMGF2/mirzabot)

> [!IMPORTANT]
> اگر نسخه تغییر‌یافته BlueBot را توزیع می‌کنید یا به‌عنوان سرویس تحت شبکه در اختیار دیگران قرار می‌دهید، الزامات AGPL-3.0 از جمله شرایط مربوط به دسترسی به Source Code را بررسی و رعایت کنید.

---

<div align="center">

### 🔵 BlueBot

**ساخته‌شده برای مدیریت ساده‌تر، حرفه‌ای‌تر و قابل توسعه‌تر فروش سرویس‌های VPN**

[Repository](https://github.com/hazhanhasani/bluebot) • [Actions](https://github.com/hazhanhasani/bluebot/actions) • [License](LICENSE) • [Notice](NOTICE.md)

<sub>Maintained by hazhanhasani • Version 0.5.8</sub>

</div>
