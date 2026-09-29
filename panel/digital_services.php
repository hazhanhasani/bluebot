<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/../src/Services/DigitalServiceManager.php';

require_auth();

if (!BluebotDigitalServices::isAvailable($pdo)) {
    http_response_code(503);
    $pageTitle = 'فروش خدمات';
    $pageLede = 'مدیریت سرویس‌های دیجیتال';
    $activeNav = 'digital-services';
    include __DIR__ . '/inc/layout_head.php';
    ?>
<div class="card fade-up" style="margin-bottom:16px">
    <div class="card-head">
        <div>
            <div class="card-title">مدیریت فروش خدمات</div>
            <div class="card-subtitle">این صفحه فقط برای اتصال API، درصد سود، موجودی و Sync است. مدیریت داده‌ها در بخش‌های اصلی پنل انجام می‌شود.</div>
        </div>
    </div>
    <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">
        <a href="service.php?scope=digital" class="btn btn-ghost" style="justify-content:center;padding:14px">📦 مدیریت سرویس‌ها</a>
        <a href="invoice.php?scope=digital" class="btn btn-ghost" style="justify-content:center;padding:14px">🧾 مدیریت سفارش‌ها</a>
        <a href="category.php?scope=digital" class="btn btn-ghost" style="justify-content:center;padding:14px">🗂 مدیریت دسته‌بندی‌ها</a>
    </div>
</div>

<div class="two-col">
<div class="card fade-up d1" id="tgtools">
        <div class="card-head">
            <div>
                <div class="card-title">TGTools API</div>
                <div class="card-subtitle">ارسال Stars و Premium پس از تأیید دستی ادمین؛ وضعیت سفارش خودکار پیگیری می‌شود.</div>
            </div>
        </div>
        <form method="post" class="card-body" style="display:grid;gap:12px">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_tgtools">
            <div class="field">
                <label>Base URL</label>
                <input class="input" value="https://api.tg-tools.shop" disabled dir="ltr">
            </div>
            <div class="field">
                <label>API Key</label>
                <input class="input" type="password" name="tgtools_api_key" autocomplete="new-password"
                    placeholder="<?= $tgApiKey !== '' ? '•••••••• (ذخیره شده؛ برای تغییر وارد کنید)' : 'tgt_...' ?>">
                <small class="field-hint">کلید از Settings → API Keys در TGTools ساخته می‌شود و در پیام‌های ربات نمایش داده نمی‌شود.</small>
            </div>
            <div class="notice <?= !empty($tgWalletStatus['ok']) ? 'notice-info' : 'notice-warn' ?>">
                <strong>کیف پول API TGTools:</strong>
                <?php if (!empty($tgWalletStatus['ok'])): ?>
                    موجودی:
                    <code><?= is_numeric($tgWalletStatus['balance_ton'] ?? null)
                        ? htmlspecialchars(rtrim(rtrim(number_format((float) $tgWalletStatus['balance_ton'], 6, '.', ''), '0'), '.'))
                        : 'نامشخص' ?> TON</code>
                    <?php if (!empty($tgWalletStatus['deposit_address'])): ?>
                        <br>آدرس واریز:
                        <code><?= htmlspecialchars((string) $tgWalletStatus['deposit_address']) ?></code>
                    <?php endif; ?>
                <?php else: ?>
                    <?= htmlspecialchars((string) ($tgWalletStatus['message'] ?? 'دریافت موجودی ناموفق بود.')) ?>
                <?php endif; ?>
                <br><small>
                    اتصال Tonkeeper به سایت به‌تنهایی موجودی API را تأمین نمی‌کند؛ سفارش API از موجودی کیف پول TGTools کسر می‌شود.
                </small>
            </div>
            <div class="two-col" style="gap:10px">
                <div class="field">
                    <label>حاشیه سود Stars (%)</label>
                    <input class="input" type="number" name="tgtools_stars_profit_percent" min="0" max="1000" step="0.1"
                        value="<?= htmlspecialchars((string) $tgStarsProfit) ?>" required>
                </div>
                <div class="field">
                    <label>حاشیه سود Premium (%)</label>
                    <input class="input" type="number" name="tgtools_premium_profit_percent" min="0" max="1000" step="0.1"
                        value="<?= htmlspecialchars((string) $tgPremiumProfit) ?>" required>
                </div>
            </div>
            <div class="field">
                <label>نرخ هر 1 TON به تومان</label>
                <input class="input" type="number" name="tgtools_ton_toman_rate" min="0" step="1"
                    value="<?= htmlspecialchars((string) $tgTonRateToman) ?>" placeholder="مثلاً 350000">
                <small class="field-hint">قیمت فروش TGTools = قیمت عمده TON × نرخ تومان × (۱ + درصد سود). در پایان به هزار تومان رو به بالا گرد می‌شود.</small>
            </div>
            <div class="notice notice-info">
                BlueBot بسته‌های Stars و Premium را از <code>/api/purchase/prices</code> می‌خواند، قیمت عمده را دریافت می‌کند و قیمت فروش را خودکار می‌سازد.
                حاشیه سود Stars و Premium مستقل است؛ نیازی به واردکردن قیمت تک‌تک محصولات یا Provider Service Code نیست.
            </div>
            <button class="btn btn-primary" type="submit"><?= icon('check', 14) ?> ذخیره TGTools</button>
        </form>
        <form method="post" class="card-body" style="padding-top:0">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="sync_tgtools_catalog">
            <button class="btn btn-ghost" type="submit">↻ ساخت/همگام‌سازی خودکار محصولات</button>
        </form>
    </div>

    <div class="card fade-up d1" id="ozvinoo">
        <div class="card-head">
            <div>
                <div class="card-title">عضوینو / OZVinoo</div>
                <div class="card-subtitle">اتصال مستقیم به API رسمی Stars، Premium و شماره مجازی؛ بدون حدس‌زدن endpoint.</div>
            </div>
        </div>
        <form method="post" class="card-body" style="display:grid;gap:12px" data-ozvinoo-sync-form>
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_ozvinoo">

            <div class="field">
                <label>Base URL</label>
                <input class="input" value="https://api.ozvinoo.xyz" disabled dir="ltr">
            </div>

            <div class="field">
                <label>API Key</label>
                <input class="input" type="password" name="ozvinoo_api_key" autocomplete="new-password"
                    placeholder="<?= $ozApiKey !== '' ? '•••••••• (ذخیره شده؛ برای تغییر وارد کنید)' : 'xxxx-xxxx-xxxx-xxxx' ?>">
                <small class="field-hint">برای درخواست‌های Stars/Premium/Numbers با Bearer استفاده می‌شود؛ موجودی نیز از endpoint رسمی حساب خوانده می‌شود.</small>
            </div>

            <div class="two-col" style="gap:10px">
                <div class="field">
                    <label>درصد سود همه محصولات عضوینو</label>
                    <input class="input" type="number" name="ozvinoo_profit_percent" min="0" max="1000" step="0.1"
                        value="<?= htmlspecialchars((string) $ozProfitPercent) ?>" required>
                    <small class="field-hint">روی قیمت عمده Stars، Premium و تمام کشورهای شماره مجازی اعمال می‌شود.</small>
                </div>
                <div class="field">
                    <label>بروزرسانی خودکار (دقیقه)</label>
                    <input class="input" type="number" name="ozvinoo_sync_interval_minutes" min="1" max="1440"
                        value="<?= htmlspecialchars((string) $ozSyncInterval) ?>" required>
                </div>
            </div>

            <div class="notice <?= !empty($ozWalletStatus['ok']) ? 'notice-info' : 'notice-warn' ?>">
                <strong>کیف پول API عضوینو:</strong>
                <?php if (!empty($ozWalletStatus['ok'])): ?>
                    <code><?= number_format((float) ($ozWalletStatus['balance'] ?? 0)) ?> تومان</code>
                <?php else: ?>
                    <?= htmlspecialchars((string) ($ozWalletStatus['message'] ?? 'دریافت موجودی ناموفق بود.')) ?>
                <?php endif; ?>
            </div>

            <div class="notice notice-info">
                <strong>Endpointهای فعال:</strong><br>
                <code>GET /telegram-services/stars/</code> · <code>POST /telegram-services/stars/</code><br>
                <code>GET /telegram-services/premium/</code> · <code>POST /telegram-services/premium/</code><br>
                <code>GET/POST /telegram-numbers/numbers/</code> · <code>GET /telegram-numbers/number-services/</code><br>
                <small>BlueBot دیگر مسیر <code>/api/</code> یا SMM catalog را برای عضوینو probe نمی‌کند.</small>
            </div>

            <div class="notice <?= $digitalServicesMenuEnabled ? 'notice-info' : 'notice-warn' ?>">
                <strong>نمایش فروش خدمات در منوی ربات:</strong>
                <?= $digitalServicesMenuEnabled ? '✅ فعال' : '⚠️ غیرفعال' ?>
                <?php if (!$digitalServicesMenuEnabled): ?>
                    <br><small>در نسخه‌های ارتقایافته، مهاجرت خودکار منو با اولین پیام کاربر انجام می‌شود.</small>
                <?php endif; ?>
            </div>

            <div class="notice <?= $ozProductCount > 0 ? 'notice-info' : 'notice-warn' ?>">
                <strong>محصولات فعال عضوینو در ربات:</strong> <?= number_format($ozProductCount) ?>
                <?php if ($ozProductCount === 0): ?>
                    <br><small>پس از ذخیره API Key، محصولات رسمی عضوینو خودکار ساخته و قیمت‌گذاری می‌شوند.</small>
                <?php endif; ?>
            </div>

            <?php if (is_array($ozProvider)): ?>
                <div class="notice <?= in_array((string) ($ozProvider['last_sync_status'] ?? ''), ['success', 'partial'], true) ? 'notice-info' : 'notice-warn' ?>">
                    روش API: <code>OFFICIAL V1</code>
                    · آخرین Sync: <?= htmlspecialchars((string) ($ozProvider['last_sync_at'] ?? '—')) ?>
                    · وضعیت: <?= htmlspecialchars((string) ($ozProvider['last_sync_status'] ?? '—')) ?>
                    <?php if (!empty($ozProvider['last_sync_message'])): ?>
                        <br><small><?= htmlspecialchars((string) $ozProvider['last_sync_message']) ?></small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <button class="btn btn-primary" type="submit"><?= icon('check', 14) ?> ذخیره + همگام‌سازی رسمی عضوینو</button>
        </form>

        <form method="post" class="card-body" style="padding-top:0" data-ozvinoo-sync-form>
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="sync_ozvinoo_catalog">
            <button class="btn btn-ghost" type="submit">↻ بروزرسانی Stars / Premium / شماره‌ها</button>
        </form>
        <script>
        document.querySelectorAll('[data-ozvinoo-sync-form]').forEach(function (form) {
            form.addEventListener('submit', function () {
                var button = form.querySelector('button[type="submit"]');
                if (!button || button.disabled) return;
                button.disabled = true;
                button.dataset.originalText = button.textContent || '';
                button.textContent = '⏳ در حال همگام‌سازی API رسمی...';
            });
        });
        </script>
    </div>

</div>
<div class="card fade-up d1" id="providers" style="margin-top:16px">
    <div class="card-head">
        <div>
            <div class="card-title">ارائه‌دهندگان و کاتالوگ خودکار</div>
            <div class="card-subtitle">هر Provider را یک‌بار تعریف کنید؛ تمام محصولاتش خودکار وارد ربات، دسته‌بندی و قیمت‌گذاری می‌شوند.</div>
        </div>
    </div>

    <form method="post" class="card-body" style="display:grid;gap:12px">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_provider_catalog">

        <div class="two-col" style="gap:10px">
            <div class="field">
                <label>نام ارائه‌دهنده</label>
                <input class="input" name="provider_name" maxlength="190" required placeholder="مثلاً SocialProvider">
            </div>
            <div class="field">
                <label>کلید داخلی</label>
                <input class="input" name="provider_key" maxlength="50" required dir="ltr" placeholder="socialprovider">
                <small class="field-hint">حروف انگلیسی کوچک، عدد، خط تیره یا زیرخط. <code>tgtools</code> رزرو شده است.</small>
            </div>
        </div>

        <div class="field">
            <label>Catalog URL</label>
            <input class="input" name="catalog_url" type="url" required dir="ltr" placeholder="https://provider.example/api/products">
            <small class="field-hint">فقط HTTPS عمومی پذیرفته می‌شود. پاسخ باید JSON باشد.</small>
        </div>

        <div class="two-col" style="gap:10px">
            <div class="field">
                <label>API Key (اختیاری)</label>
                <input class="input" type="password" name="provider_api_key" autocomplete="new-password" dir="ltr">
            </div>
            <div class="field">
                <label>حاشیه سود Provider (%)</label>
                <input class="input" type="number" name="profit_percent" min="0" max="1000" step="0.1" required placeholder="20">
            </div>
        </div>

        <div class="two-col" style="gap:10px">
            <div class="field">
                <label>Auth Header</label>
                <input class="input" name="provider_auth_header" value="Authorization" dir="ltr">
            </div>
            <div class="field">
                <label>Auth Prefix</label>
                <input class="input" name="provider_auth_prefix" value="Bearer" dir="ltr">
            </div>
        </div>

        <div class="two-col" style="gap:10px">
            <div class="field">
                <label>ارز قیمت عمده</label>
                <select class="select" name="provider_currency" required>
                    <option value="toman">تومان</option>
                    <option value="rial">ریال</option>
                    <option value="usd">USD</option>
                    <option value="ton">TON</option>
                    <option value="other">سایر</option>
                </select>
            </div>
            <div class="field">
                <label>نرخ هر واحد ارز به تومان</label>
                <input class="input" type="number" name="exchange_rate_toman" min="0.000001" step="0.000001" value="1" required>
                <small class="field-hint">برای تومان ۱ و برای ریال ۰.۱ خودکار اعمال می‌شود.</small>
            </div>
        </div>

        <details>
            <summary style="cursor:pointer;font-weight:700">تنظیم ساختار JSON کاتالوگ</summary>
            <div style="display:grid;gap:10px;margin-top:12px">
                <div class="two-col" style="gap:10px">
                    <div class="field">
                        <label>Products path</label>
                        <input class="input" name="products_path" value="auto" dir="ltr" placeholder="data.items">
                    </div>
                    <div class="field">
                        <label>Product ID field</label>
                        <input class="input" name="id_field" value="auto" dir="ltr">
                    </div>
                </div>
                <div class="two-col" style="gap:10px">
                    <div class="field">
                        <label>Name field</label>
                        <input class="input" name="name_field" value="auto" dir="ltr">
                    </div>
                    <div class="field">
                        <label>Category field</label>
                        <input class="input" name="category_field" value="auto" dir="ltr">
                    </div>
                </div>
                <div class="two-col" style="gap:10px">
                    <div class="field">
                        <label>Price field</label>
                        <input class="input" name="price_field" value="auto" dir="ltr">
                    </div>
                    <div class="field">
                        <label>فاصله همگام‌سازی (دقیقه)</label>
                        <input class="input" type="number" name="sync_interval_minutes" min="1" max="1440" value="15">
                    </div>
                </div>
                <small class="field-hint">پیش‌فرض <code>auto</code> است؛ BlueBot ساختار رایج JSON را خودش تشخیص می‌دهد. برای APIهای خاص می‌توانید مسیرهایی مثل <code>service.id</code> وارد کنید.</small>
            </div>
        </details>

        <div class="notice notice-info">
            قیمت فروش تمام محصولات این Provider به‌صورت خودکار از قیمت عمده + درصد سود ساخته می‌شود. محصول حذف‌شده از API نیز در ربات خودکار غیرفعال می‌شود.
        </div>

        <button class="btn btn-primary" type="submit"><?= icon('plus', 14) ?> افزودن/بروزرسانی Provider + همگام‌سازی</button>
    </form>

    <div class="card-body" style="padding-top:0">
        <?php if ($providerCatalogs === []): ?>
            <div class="empty"><p>هنوز Provider عمومی تعریف نشده است.</p></div>
        <?php else: ?>
            <div style="display:grid;gap:10px">
                <?php foreach ($providerCatalogs as $providerCatalog): ?>
                    <div class="notice <?= (int) $providerCatalog['active'] === 1 ? 'notice-info' : 'notice-warn' ?>" style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
                        <div>
                            <strong><?= htmlspecialchars($providerCatalog['name']) ?></strong>
                            <span class="cell-mono"> · <?= htmlspecialchars($providerCatalog['provider_key']) ?></span>
                            <div class="field-hint" style="margin-top:4px">
                                سود: <?= htmlspecialchars((string) $providerCatalog['profit_percent']) ?>٪
                                · ارز: <?= htmlspecialchars(strtoupper((string) $providerCatalog['currency'])) ?>
                                · آخرین Sync: <?= htmlspecialchars((string) ($providerCatalog['last_sync_at'] ?? '—')) ?>
                                <?php if (!empty($providerCatalog['last_sync_status'])): ?>
                                    · <?= htmlspecialchars((string) $providerCatalog['last_sync_status']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <form method="post">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="action" value="sync_provider_catalog">
                                <input type="hidden" name="provider_key" value="<?= htmlspecialchars($providerCatalog['provider_key']) ?>">
                                <button class="btn btn-ghost btn-sm" type="submit">↻ Sync</button>
                            </form>
                            <form method="post">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="action" value="toggle_provider_catalog">
                                <input type="hidden" name="provider_key" value="<?= htmlspecialchars($providerCatalog['provider_key']) ?>">
                                <button class="btn btn-ghost btn-sm" type="submit"><?= (int) $providerCatalog['active'] === 1 ? 'غیرفعال' : 'فعال' ?></button>
                            </form>
                            <form method="post" data-confirm="ارائه‌دهنده حذف شود؟">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_provider_catalog">
                                <input type="hidden" name="provider_key" value="<?= htmlspecialchars($providerCatalog['provider_key']) ?>">
                                <button class="btn btn-no btn-sm" type="submit"><?= icon('trash', 12) ?></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>


<?php include __DIR__ . '/inc/layout_foot.php'; ?>