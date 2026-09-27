<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/../src/Support/SmsService.php';
require_auth();

BluebotSms::seedTemplates();

function sms_redirect(string $message, bool $error = false): void
{
    flash($error ? 'error' : 'success', $message);
    header('Location: sms.php');
    exit;
}

function sms_parse_json_params(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('JSON پارامترها معتبر نیست.');
    }
    return $decoded;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save_settings') {
            $current = BluebotSms::settings();
            $base = rtrim(trim((string) ($_POST['base_url'] ?? 'https://api.iranpayamak.com/ws/v1')), '/');
            if ($base === '' || !preg_match('~^https://~i', $base)) {
                throw new RuntimeException('Base URL باید با https:// شروع شود.');
            }

            $apiRaw = trim((string) ($_POST['api_key'] ?? ''));
            $apiEnc = $apiRaw !== ''
                ? BluebotSms::encryptSecret($apiRaw)
                : (string) ($current['api_key_enc'] ?? '');

            $days = [];
            foreach (preg_split('/[,،;\s]+/', strtr((string) ($_POST['reminder_days'] ?? '3,2,1'), '۰۱۲۳۴۵۶۷۸۹', '0123456789')) ?: [] as $value) {
                $n = (int) $value;
                if ($n >= 1 && $n <= 30 && !in_array($n, $days, true)) {
                    $days[] = $n;
                }
            }
            if ($days === []) {
                $days = [3, 2, 1];
            }
            rsort($days, SORT_NUMERIC);

            $stmt = $pdo->prepare(
                'UPDATE sms_settings
                 SET provider=?,base_url=?,api_key_enc=?,active=?,otp_active=?,
                     otp_ttl_seconds=?,otp_resend_seconds=?,otp_max_attempts=?,
                     reminder_days_json=?,low_volume_threshold_gb=?,retry_max_attempts=?,verify_tls=?,updated_at=?
                 WHERE id=1'
            );
            $stmt->execute([
                'iranpayamak',
                $base,
                $apiEnc,
                isset($_POST['active']) ? 1 : 0,
                isset($_POST['otp_active']) ? 1 : 0,
                max(60, min(600, (int) ($_POST['otp_ttl_seconds'] ?? 120))),
                max(30, min(600, (int) ($_POST['otp_resend_seconds'] ?? 60))),
                max(3, min(10, (int) ($_POST['otp_max_attempts'] ?? 5))),
                json_encode($days, JSON_UNESCAPED_UNICODE),
                max(1, min(9999, (int) ($_POST['low_volume_threshold_gb'] ?? 5))),
                max(1, min(5, (int) ($_POST['retry_max_attempts'] ?? 3))),
                isset($_POST['verify_tls']) ? 1 : 0,
                time(),
            ]);

            $message = 'تنظیمات پیامک ذخیره شد.';
            $savedSettings = BluebotSms::settings();
            if (BluebotSms::decryptSecret((string) ($savedSettings['api_key_enc'] ?? '')) !== '') {
                @unlink(__DIR__ . '/../storage/cache/sms_patterns.json');
                @unlink(__DIR__ . '/../storage/cache/sms_lines.json');

                try {
                    $lines = BluebotSms::refreshLines(true);
                    $message .= ' خط ارسال ' . (string) ($lines['selected'] ?? '') .
                        ' از بین ' . number_format((int) ($lines['count'] ?? 0)) .
                        ' خط قابل‌استفاده به‌صورت خودکار انتخاب شد.';
                } catch (Throwable $lineError) {
                    $message .= ' Sync خط ارسال انجام نشد: ' . $lineError->getMessage();
                }

                try {
                    $sync = BluebotSms::refreshPatterns(true);
                    $smart = BluebotSms::smartAssignPatterns((array) ($sync['patterns'] ?? []), false);
                    $message .= ' ' . number_format((int) ($sync['count'] ?? 0)) .
                        ' پترن فعال از همه صفحات همگام شد و ' . number_format((int) ($smart['assigned'] ?? 0)) .
                        ' پترن خالی هوشمند جایگذاری شد.';
                } catch (Throwable $syncError) {
                    $message .= ' Sync پترن‌ها انجام نشد: ' . $syncError->getMessage();
                }
            }

            sms_redirect($message);
        }

        if ($action === 'refresh_lines') {
            $result = BluebotSms::refreshLines(true);
            sms_redirect(
                number_format((int) ($result['count'] ?? 0)) .
                ' خط قابل‌استفاده دریافت شد؛ خط ' .
                (string) ($result['selected'] ?? '—') .
                ' به‌صورت خودکار انتخاب شد.'
            );
        }

        if ($action === 'refresh_patterns') {
            $result = BluebotSms::refreshPatterns(true);
            $smart = BluebotSms::smartAssignPatterns((array) ($result['patterns'] ?? []), false);
            sms_redirect(
                number_format((int) ($result['count'] ?? 0)) .
                ' پترن فعال دریافت شد؛ ' .
                number_format((int) ($smart['assigned'] ?? 0)) .
                ' مورد خالی به‌صورت هوشمند جایگذاری شد.'
            );
        }

        if ($action === 'smart_assign') {
            $cache = BluebotSms::patternCache();
            $patterns = (array) ($cache['patterns'] ?? []);
            if ($patterns === []) {
                throw new RuntimeException('ابتدا فهرست پترن‌ها را تازه‌سازی کنید.');
            }
            $result = BluebotSms::smartAssignPatterns($patterns, !empty($_POST['overwrite']));
            sms_redirect('جایگذاری هوشمند انجام شد: ' . number_format((int) ($result['assigned'] ?? 0)) . ' مورد.');
        }

        if ($action === 'save_templates') {
            $patterns = is_array($_POST['pattern'] ?? null) ? $_POST['pattern'] : [];
            $enabled = is_array($_POST['enabled'] ?? null) ? $_POST['enabled'] : [];
            $validCodes = [];
            foreach ((array) (BluebotSms::patternCache()['patterns'] ?? []) as $pattern) {
                if (!empty($pattern['code'])) {
                    $validCodes[(string) $pattern['code']] = true;
                }
            }

            $stmt = $pdo->prepare('UPDATE sms_templates SET pattern_code=?,enabled=?,updated_at=? WHERE event_key=?');
            foreach (BluebotSms::templates() as $template) {
                $key = (string) $template['event_key'];
                $code = trim((string) ($patterns[$key] ?? ''));
                if ($code !== '' && $validCodes !== [] && !isset($validCodes[$code])) {
                    throw new RuntimeException('پترن انتخاب‌شده برای «' . $template['title'] . '» دیگر فعال نیست.');
                }
                $stmt->execute([$code, isset($enabled[$key]) ? 1 : 0, time(), $key]);
            }
            sms_redirect('تنظیمات همه پترن‌ها ذخیره شد.');
        }

        if ($action === 'test_sms') {
            $event = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_POST['event_key'] ?? ''))) ?: '';
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $params = sms_parse_json_params((string) ($_POST['params_json'] ?? '{}'));
            $result = BluebotSms::sendTemplateNow($event, $phone, $params);
            $providerId = '';
            foreach (['message_id','messageId','id','uid','data'] as $key) {
                if (isset($result[$key]) && is_scalar($result[$key])) {
                    $providerId = (string) $result[$key];
                    break;
                }
            }
            sms_redirect('پیام تست با موفقیت به Provider تحویل شد' . ($providerId !== '' ? '؛ ID: ' . $providerId : '') . '.');
        }

        if ($action === 'broadcast') {
            $message = trim((string) ($_POST['message'] ?? ''));
            if ($message === '') {
                throw new RuntimeException('متن اطلاعیه خالی است.');
            }
            $count = BluebotSms::broadcast(
                'admin_announcement',
                ['message' => mb_substr($message, 0, 120)],
                (string) ($_POST['audience'] ?? 'active') !== 'all'
            );
            sms_redirect(number_format($count) . ' پیامک در صف ارسال عمومی قرار گرفت.');
        }

        if ($action === 'process_queue') {
            $result = BluebotSms::processQueue(100);
            sms_redirect(
                'صف پردازش شد؛ ' .
                number_format((int) $result['sent']) . ' موفق و ' .
                number_format((int) $result['failed']) . ' خطا/Retry.'
            );
        }

        if ($action === 'retry') {
            $id = trim((string) ($_POST['delivery_id'] ?? ''));
            if ($id === '' || !BluebotSms::retry($id)) {
                throw new RuntimeException('پیام برای Retry پیدا نشد.');
            }
            $result = BluebotSms::dispatchNow($id);
            sms_redirect(!empty($result['sent']) ? 'پیامک با موفقیت دوباره ارسال شد.' : 'پیام برای Retry فعال شد: ' . (string) ($result['message'] ?? ''));
        }
    } catch (Throwable $e) {
        sms_redirect($e->getMessage(), true);
    }
}

$settings = BluebotSms::settings();
$templates = BluebotSms::templates();
$cache = BluebotSms::patternCache();
$providerPatterns = (array) ($cache['patterns'] ?? []);
$lineCache = BluebotSms::lineCache();
$catalog = BluebotSms::catalog();
$stats = BluebotSms::stats();
$recent = BluebotSms::recent(100);

$patternByCode = [];
foreach ($providerPatterns as $pattern) {
    if (!empty($pattern['code'])) {
        $patternByCode[(string) $pattern['code']] = $pattern;
    }
}

$normalizeVariableType = static function (string $type): string {
    $type = strtolower(trim($type));

    return in_array(
        $type,
        ['number', 'numeric', 'int', 'integer', 'float', 'double', 'decimal'],
        true
    ) ? 'number' : 'string';
};

$renderVariableSpecs = static function (array $vars) use ($normalizeVariableType): string {
    $items = [];

    foreach ($vars as $var) {
        if (is_string($var)) {
            $var = ['name' => $var, 'type' => 'string', 'length' => 160];
        }
        if (!is_array($var) || empty($var['name'])) {
            continue;
        }

        $name = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $var['name']) ?: '';
        if ($name === '') {
            continue;
        }

        $rawType = strtolower(trim((string) ($var['type'] ?? 'unknown')));
        $type = $rawType === '' || $rawType === 'unknown' ? 'unknown' : $normalizeVariableType($rawType);
        $typeLabel = $type === 'number' ? 'عدد' : ($type === 'string' ? 'متن' : 'نامشخص');
        $length = max(1, min(500, (int) ($var['length'] ?? 160)));

        $items[] = '<div class="sms-var-chip">'
            . '<code class="sms-var-name">%' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '%</code>'
            . '<span class="sms-var-type">' . $typeLabel . '</span>'
            . '<span class="sms-var-limit">حداکثر ' . number_format($length) . '</span>'
            . '</div>';
    }

    return $items
        ? '<div class="sms-var-list">' . implode('', $items) . '</div>'
        : '<span class="sms-var-empty">بدون متغیر</span>';
};

$pageTitle = 'پیامک و اعلان‌ها';
$pageLede = 'مدیریت کامل فراز اس‌ام‌اس / ایران‌پیامک، پترن‌ها، اعلان سرویس و صف ارسال — بدون نیاز به تنظیم داخل ربات';
$activeNav = 'sms';
include __DIR__ . '/inc/layout_head.php';
?>
<style>
.sms-var-list{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.sms-var-chip{display:inline-flex;align-items:center;gap:7px;padding:7px 9px;border:1px solid var(--line);border-radius:10px;background:color-mix(in srgb,var(--card) 82%,transparent);white-space:nowrap}
.sms-var-name{direction:ltr;unicode-bidi:isolate;font-size:12px}
.sms-var-type{font-size:12px;font-weight:800;color:var(--text)}
.sms-var-limit{font-size:11px;color:var(--mute)}
.sms-var-empty{color:var(--mute);font-size:12px}
@media (max-width:760px){
    .sms-mobile-table{display:block;width:100%}
    .sms-mobile-table thead{display:none}
    .sms-mobile-table tbody{display:grid;gap:12px;padding:12px}
    .sms-mobile-table tr{display:block;border:1px solid var(--line);border-radius:16px;background:var(--card);overflow:hidden}
    .sms-mobile-table td{display:grid;grid-template-columns:92px minmax(0,1fr);gap:10px;align-items:start;width:auto!important;min-width:0!important;padding:11px 12px!important;border:0!important;border-bottom:1px solid var(--line)!important;white-space:normal!important;word-break:break-word}
    .sms-mobile-table td:last-child{border-bottom:0!important}
    .sms-mobile-table td::before{content:attr(data-label);font-size:11px;font-weight:800;color:var(--mute)}
    .sms-mobile-table .select{width:100%;min-width:0!important}
    .sms-mobile-table code{white-space:pre-wrap;word-break:break-word}
    .sms-var-list{gap:6px}
    .sms-var-chip{width:100%;justify-content:space-between}
}
</style>

<div class="stats-row fade-up" style="grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:16px">
    <div class="stat-card"><div class="stat-label">در صف</div><div class="stat-num"><?= number_format($stats['pending'] + $stats['retry'] + $stats['sending']) ?></div></div>
    <div class="stat-card"><div class="stat-label">ارسال موفق</div><div class="stat-num"><?= number_format($stats['sent']) ?></div></div>
    <div class="stat-card"><div class="stat-label">ناموفق</div><div class="stat-num"><?= number_format($stats['failed']) ?></div></div>
    <div class="stat-card"><div class="stat-label">پترن فعال Provider</div><div class="stat-num"><?= number_format(count($providerPatterns)) ?></div></div>
</div>

<?php if (!empty($settings['last_test_at'])): ?>
    <div class="card fade-up" style="margin-bottom:16px">
        <div class="card-body" style="padding:16px">
            <strong style="color:<?= !empty($settings['last_test_ok']) ? 'var(--ok)' : 'var(--no)' ?>">
                <?= !empty($settings['last_test_ok']) ? '✅ آخرین ارتباط SMS موفق' : '❌ آخرین ارتباط SMS ناموفق' ?>
            </strong>
            <p style="margin:8px 0 0;color:var(--mute)"><?= htmlspecialchars((string) ($settings['last_test_message'] ?? '')) ?></p>
            <small class="cf"><?= date('Y/m/d H:i:s', (int) $settings['last_test_at']) ?></small>
        </div>
    </div>
<?php endif; ?>

<div class="card fade-up" style="margin-bottom:16px">
    <div class="card-head">
        <div>
            <div class="card-title">تنظیمات Provider</div>
            <div class="card-subtitle">تمام تنظیمات از همین پنل انجام می‌شود؛ API Key به‌صورت AES-256-GCM رمزنگاری می‌شود.</div>
        </div>
    </div>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="save_settings">
        <div class="card-body" style="padding:16px">
            <div class="form-grid">
                <div class="field">
                    <label>Provider</label>
                    <input class="input" value="FarazSMS / IranPayamak" disabled>
                </div>
                <div class="field">
                    <label>Base URL</label>
                    <input class="input" dir="ltr" name="base_url" value="<?= htmlspecialchars((string) ($settings['base_url'] ?? 'https://api.iranpayamak.com/ws/v1')) ?>" required>
                </div>
                <div class="field">
                    <label>API Key</label>
                    <input class="input" dir="ltr" type="password" name="api_key" placeholder="خالی = حفظ کلید فعلی">
                </div>
                <div class="field">
                    <label>خط ارسال خودکار</label>
                    <input class="input" dir="ltr" value="<?= htmlspecialchars((string) ($lineCache['selected'] ?? $settings['from_number'] ?? 'در انتظار Sync')) ?>" disabled>
                    <small style="color:var(--mute)">
                        بلو پنل از <code>/lines/accessible</code> خطوط مجاز همین API Key را می‌خواند و خط مناسب را خودکار انتخاب می‌کند.
                    </small>
                </div>
                <div class="field">
                    <label>روزهای یادآوری پایان سرویس</label>
                    <input class="input" dir="ltr" name="reminder_days" value="<?= htmlspecialchars(implode(',', json_decode((string) ($settings['reminder_days_json'] ?? '[3,2,1]'), true) ?: [3,2,1])) ?>">
                </div>
                <div class="field">
                    <label>هشدار حجم کمتر از GB</label>
                    <input class="input" type="number" min="1" max="9999" name="low_volume_threshold_gb" value="<?= (int) ($settings['low_volume_threshold_gb'] ?? 5) ?>">
                </div>
                <div class="field">
                    <label>حداکثر تلاش ارسال</label>
                    <input class="input" type="number" min="1" max="5" name="retry_max_attempts" value="<?= (int) ($settings['retry_max_attempts'] ?? 3) ?>">
                </div>
                <div class="field">
                    <label>اعتبار کد OTP (ثانیه)</label>
                    <input class="input" type="number" min="60" max="600" name="otp_ttl_seconds" value="<?= (int) ($settings['otp_ttl_seconds'] ?? 120) ?>">
                </div>
                <div class="field">
                    <label>ارسال مجدد OTP بعد از (ثانیه)</label>
                    <input class="input" type="number" min="30" max="600" name="otp_resend_seconds" value="<?= (int) ($settings['otp_resend_seconds'] ?? 60) ?>">
                </div>
                <div class="field">
                    <label>حداکثر تلاش OTP</label>
                    <input class="input" type="number" min="3" max="10" name="otp_max_attempts" value="<?= (int) ($settings['otp_max_attempts'] ?? 5) ?>">
                </div>
                <div class="field" style="justify-content:end">
                    <label><input type="checkbox" name="active" value="1" <?= !empty($settings['active']) ? 'checked' : '' ?>> سیستم پیامک فعال باشد</label>
                    <label><input type="checkbox" name="otp_active" value="1" <?= !empty($settings['otp_active']) ? 'checked' : '' ?>> تأیید شماره ربات با OTP فراز اس‌ام‌اس</label>
                    <label><input type="checkbox" name="verify_tls" value="1" <?= !isset($settings['verify_tls']) || (int) $settings['verify_tls'] === 1 ? 'checked' : '' ?>> بررسی TLS فعال باشد</label>
                </div>
            </div>
        </div>
        <div class="card-foot" style="padding:14px 16px;display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn btn-primary" type="submit"><?= icon('check', 14) ?> ذخیره تنظیمات SMS</button>
        </div>
    </form>
    <div class="card-foot" style="padding:0 16px 14px">
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="refresh_lines">
            <button class="btn btn-ghost" type="submit">↻ تشخیص مجدد خط ارسال</button>
            <small style="margin-right:8px;color:var(--mute)">
                <?= number_format((int) ($lineCache['count'] ?? 0)) ?> خط در Cache فعلی
            </small>
        </form>
    </div>
</div>

<div class="card fade-up" style="margin-bottom:16px">
    <div class="card-head">
        <div>
            <div class="card-title">پترن‌های فراز اس‌ام‌اس / ایران‌پیامک</div>
            <div class="card-subtitle">پترن‌های فعال مستقیماً از API حساب خوانده می‌شوند و بلو پنل موارد سازگار را هوشمند جایگذاری می‌کند.</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="refresh_patterns">
                <button class="btn btn-primary" type="submit">↻ تازه‌سازی + جایگذاری هوشمند</button>
            </form>
            <form method="post" onsubmit="return confirm('انتخاب‌های فعلی با تطبیق هوشمند دوباره چیده شوند؟')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="smart_assign">
                <input type="hidden" name="overwrite" value="1">
                <button class="btn btn-ghost" type="submit">🧠 بازچینی کامل</button>
            </form>
        </div>
    </div>
    <div class="card-body" style="padding:16px">
        <?php if ($providerPatterns): ?>
            <div class="notice notice-ok">
                ✅ <?= number_format(count($providerPatterns)) ?> پترن فعال از
                <?= number_format((int) ($cache['pages_fetched'] ?? 1)) ?> صفحه بارگذاری شده است.
            </div>
            <div class="tbl-wrap" style="max-height:360px;overflow:auto">
                <table class="tbl-lg sms-mobile-table sms-pattern-table">
                    <thead><tr><th>Code</th><th>توضیح / متن</th><th>متغیرها</th></tr></thead>
                    <tbody>
                    <?php foreach ($providerPatterns as $pattern): ?>
                        <tr>
                            <td class="cm" data-label="کد"><?= htmlspecialchars((string) ($pattern['code'] ?? '')) ?></td>
                            <td data-label="متن"><?= htmlspecialchars(trunc((string) (($pattern['description'] ?? '') ?: ($pattern['text'] ?? '')), 100)) ?></td>
                            <td data-label="متغیرها"><?= $renderVariableSpecs(
                                (array) (($pattern['variable_specs'] ?? []) ?: ($pattern['variables'] ?? []))
                            ) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="notice notice-warn">هنوز پترنی Cache نشده است. API Key را ذخیره کن و «تازه‌سازی» را بزن.</div>
        <?php endif; ?>
    </div>
</div>

<div class="card fade-up" style="margin-bottom:16px">
    <div class="card-head">
        <div>
            <div class="card-title">رویدادها و پترن‌های پیام</div>
            <div class="card-subtitle">متن‌ها آماده ثبت در پترن هستند. خط اول <code>BlueVPN | بلو پنل</code> برند اجباری فرستنده است و نباید حذف یا تغییر داده شود.</div>
        </div>
    </div>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="save_templates">
        <div class="tbl-wrap">
            <table class="tbl-lg sms-mobile-table sms-template-table">
                <thead><tr><th>فعال</th><th>رویداد</th><th>دسته</th><th>متن دقیق برای ثبت در فراز SMS</th><th>متغیرها و نوع</th><th>پترن Provider</th></tr></thead>
                <tbody>
                <?php foreach ($templates as $template):
                    $vars = json_decode((string) ($template['variables_json'] ?? '[]'), true) ?: [];
                    $names = array_values(array_filter(array_map(static fn($v) => is_array($v) ? ($v['name'] ?? '') : '', $vars)));
                ?>
                    <tr>
                        <td data-label="فعال"><input type="checkbox" name="enabled[<?= htmlspecialchars((string) $template['event_key']) ?>]" value="1" <?= !empty($template['enabled']) ? 'checked' : '' ?>></td>
                        <td data-label="رویداد"><strong><?= htmlspecialchars((string) $template['title']) ?></strong><br><small class="cf cm"><?= htmlspecialchars((string) $template['event_key']) ?></small></td>
                        <td data-label="دسته"><?= htmlspecialchars((string) $template['category']) ?></td>
                        <td data-label="متن پترن" style="min-width:280px">
                            <code class="sms-pattern-copy" style="white-space:pre-wrap;display:block;line-height:1.9"><?= htmlspecialchars((string) ($catalog[$template['event_key']]['body'] ?? $template['body'] ?? '')) ?></code>
                            <small style="display:block;margin-top:8px;color:var(--mute)">متن را دقیقاً با همین شکست خط ثبت کن؛ خط برند <strong>BlueVPN | بلو پنل</strong> برای تأیید پترن الزامی است.</small>
                        </td>
                        <td data-label="متغیرها"><?= $renderVariableSpecs((array) ($catalog[$template['event_key']]['vars'] ?? $vars)) ?></td>
                        <td data-label="پترن">
                            <select class="select" name="pattern[<?= htmlspecialchars((string) $template['event_key']) ?>]" style="min-width:260px">
                                <option value="">— بدون پترن —</option>
                                <?php
                                $currentPattern = trim((string) ($template['pattern_code'] ?? ''));
                                if ($currentPattern !== '' && !isset($patternByCode[$currentPattern])): ?>
                                    <option value="<?= htmlspecialchars($currentPattern) ?>" selected>
                                        <?= htmlspecialchars($currentPattern) ?> — انتخاب فعلی (خارج از Cache)
                                    </option>
                                <?php endif; ?>
                                <?php foreach ($providerPatterns as $pattern):
                                    $code = (string) ($pattern['code'] ?? '');
                                    $label = trim((string) (($pattern['description'] ?? '') ?: ($pattern['text'] ?? '')));
                                    if ($label === '') $label = $code;
                                ?>
                                    <option value="<?= htmlspecialchars($code) ?>" <?= hash_equals((string) $template['pattern_code'], $code) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($code . ' — ' . trunc($label, 55)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-foot" style="padding:14px 16px">
            <button class="btn btn-primary" type="submit"><?= icon('check', 14) ?> ذخیره پترن‌ها</button>
        </div>
    </form>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin-bottom:16px">
    <div class="card fade-up">
        <div class="card-head"><div class="card-title">ارسال تست</div></div>
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="test_sms">
            <div class="card-body" style="padding:16px;display:grid;gap:12px">
                <div class="field"><label>رویداد</label><select class="select" name="event_key"><?php foreach ($templates as $template): ?><option value="<?= htmlspecialchars((string) $template['event_key']) ?>"><?= htmlspecialchars((string) $template['title']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>شماره تست</label><input class="input" dir="ltr" name="phone" placeholder="09123456789" required></div>
                <div class="field"><label>پارامترها JSON</label><textarea class="input" dir="ltr" name="params_json" rows="5" placeholder='{"service":"یک ماهه","username":"test01","expire_date":"1405/07/30"}'>{}</textarea></div>
                <button class="btn btn-ghost" type="submit">ارسال تست</button>
            </div>
        </form>
    </div>

    <div class="card fade-up">
        <div class="card-head"><div class="card-title">ارسال عمومی</div></div>
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="broadcast">
            <div class="card-body" style="padding:16px;display:grid;gap:12px">
                <div class="field"><label>مخاطب</label><select class="select" name="audience"><option value="active">فقط کاربران فعال</option><option value="all">همه کاربران دارای شماره</option></select></div>
                <div class="field"><label>متن اطلاعیه</label><textarea class="input" name="message" maxlength="120" rows="5" required></textarea></div>
                <button class="btn btn-ghost" type="submit" onclick="return confirm('پیامک عمومی در صف قرار بگیرد؟')">قرار دادن در صف</button>
            </div>
        </form>
    </div>
</div>

<div class="card fade-up">
    <div class="card-head">
        <div>
            <div class="card-title">گزارش ارسال</div>
            <div class="card-subtitle">ارسال‌ها Outbox پایدار دارند؛ خطاها با Retry خودکار ۱، ۵، ۱۵ و ۳۰ دقیقه‌ای مدیریت می‌شوند.</div>
        </div>
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="process_queue">
            <button class="btn btn-ghost" type="submit">⚡ پردازش فوری صف</button>
        </form>
    </div>
    <div class="tbl-wrap">
        <table class="tbl-lg">
            <thead><tr><th>رویداد</th><th>کاربر</th><th>موبایل</th><th>وضعیت</th><th>Provider ID</th><th>تلاش</th><th>خطا</th><th>زمان</th><th>عملیات</th></tr></thead>
            <tbody>
            <?php if (!$recent): ?>
                <tr><td colspan="9"><div class="empty"><p>هنوز پیامکی ثبت نشده است.</p></div></td></tr>
            <?php else: foreach ($recent as $row):
                $status = (string) $row['status'];
                $tag = $status === 'sent' ? 'tag-ok' : (in_array($status, ['failed','skipped'], true) ? 'tag-no' : 'tag-warn');
            ?>
                <tr>
                    <td class="cm"><?= htmlspecialchars((string) $row['event_key']) ?></td>
                    <td class="cm"><?= htmlspecialchars((string) ($row['user_id'] ?? '—')) ?></td>
                    <td class="cn"><?= htmlspecialchars((string) $row['phone']) ?></td>
                    <td><span class="tag <?= $tag ?>"><?= htmlspecialchars($status) ?></span></td>
                    <td class="cm"><?= htmlspecialchars((string) ($row['provider_message_id'] ?: '—')) ?></td>
                    <td class="cn"><?= (int) $row['attempts'] ?>/<?= (int) $row['max_attempts'] ?></td>
                    <td style="max-width:240px"><?= htmlspecialchars(trunc((string) ($row['last_error'] ?? ''), 80)) ?></td>
                    <td class="cf"><?= date('Y/m/d H:i', (int) ($row['sent_at'] ?: $row['created_at'])) ?></td>
                    <td>
                        <?php if (in_array($status, ['failed','skipped','retry'], true)): ?>
                            <form method="post">
                                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="retry">
                                <input type="hidden" name="delivery_id" value="<?= htmlspecialchars((string) $row['id']) ?>">
                                <button class="btn btn-ghost btn-sm" type="submit">ارسال مجدد</button>
                            </form>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
