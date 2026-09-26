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
                 SET provider=?,base_url=?,api_key_enc=?,from_number=?,active=?,
                     reminder_days_json=?,low_volume_threshold_gb=?,retry_max_attempts=?,verify_tls=?,updated_at=?
                 WHERE id=1'
            );
            $stmt->execute([
                'iranpayamak',
                $base,
                $apiEnc,
                trim((string) ($_POST['from_number'] ?? '')),
                isset($_POST['active']) ? 1 : 0,
                json_encode($days, JSON_UNESCAPED_UNICODE),
                max(1, min(9999, (int) ($_POST['low_volume_threshold_gb'] ?? 5))),
                max(1, min(5, (int) ($_POST['retry_max_attempts'] ?? 3))),
                isset($_POST['verify_tls']) ? 1 : 0,
                time(),
            ]);

            if ($apiRaw !== '') {
                @unlink(__DIR__ . '/../storage/cache/sms_patterns.json');
            }

            sms_redirect('تنظیمات پیامک ذخیره شد.');
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
$stats = BluebotSms::stats();
$recent = BluebotSms::recent(100);

$patternByCode = [];
foreach ($providerPatterns as $pattern) {
    if (!empty($pattern['code'])) {
        $patternByCode[(string) $pattern['code']] = $pattern;
    }
}

$pageTitle = 'پیامک و اعلان‌ها';
$pageLede = 'مدیریت کامل فراز اس‌ام‌اس / ایران‌پیامک، پترن‌ها، اعلان سرویس و صف ارسال — بدون نیاز به تنظیم داخل ربات';
$activeNav = 'sms';
include __DIR__ . '/inc/layout_head.php';
?>

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
                    <label>شماره خط ارسال</label>
                    <input class="input" dir="ltr" name="from_number" value="<?= htmlspecialchars((string) ($settings['from_number'] ?? '')) ?>" required>
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
                <div class="field" style="justify-content:end">
                    <label><input type="checkbox" name="active" value="1" <?= !empty($settings['active']) ? 'checked' : '' ?>> سیستم پیامک فعال باشد</label>
                    <label><input type="checkbox" name="verify_tls" value="1" <?= !isset($settings['verify_tls']) || (int) $settings['verify_tls'] === 1 ? 'checked' : '' ?>> بررسی TLS فعال باشد</label>
                </div>
            </div>
        </div>
        <div class="card-foot" style="padding:14px 16px">
            <button class="btn btn-primary" type="submit"><?= icon('check', 14) ?> ذخیره تنظیمات SMS</button>
        </div>
    </form>
</div>

<div class="card fade-up" style="margin-bottom:16px">
    <div class="card-head">
        <div>
            <div class="card-title">پترن‌های فراز اس‌ام‌اس / ایران‌پیامک</div>
            <div class="card-subtitle">پترن‌های فعال مستقیماً از API حساب خوانده می‌شوند و BlueBot می‌تواند موارد سازگار را هوشمند جایگذاری کند.</div>
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
            <div class="notice notice-ok">✅ <?= number_format(count($providerPatterns)) ?> پترن فعال بارگذاری شده است.</div>
            <div class="tbl-wrap" style="max-height:360px;overflow:auto">
                <table class="tbl-lg">
                    <thead><tr><th>Code</th><th>توضیح / متن</th><th>متغیرها</th></tr></thead>
                    <tbody>
                    <?php foreach ($providerPatterns as $pattern): ?>
                        <tr>
                            <td class="cm"><?= htmlspecialchars((string) ($pattern['code'] ?? '')) ?></td>
                            <td><?= htmlspecialchars(trunc((string) (($pattern['description'] ?? '') ?: ($pattern['text'] ?? '')), 100)) ?></td>
                            <td class="cm"><?= htmlspecialchars(implode('، ', (array) ($pattern['variables'] ?? [])) ?: 'بدون متغیر') ?></td>
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
            <div class="card-subtitle">هر پیام را روشن/خاموش کن و پترن Provider را از پنل انتخاب کن.</div>
        </div>
    </div>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="save_templates">
        <div class="tbl-wrap">
            <table class="tbl-lg">
                <thead><tr><th>فعال</th><th>رویداد</th><th>دسته</th><th>متغیرهای لازم</th><th>پترن Provider</th></tr></thead>
                <tbody>
                <?php foreach ($templates as $template):
                    $vars = json_decode((string) ($template['variables_json'] ?? '[]'), true) ?: [];
                    $names = array_values(array_filter(array_map(static fn($v) => is_array($v) ? ($v['name'] ?? '') : '', $vars)));
                ?>
                    <tr>
                        <td><input type="checkbox" name="enabled[<?= htmlspecialchars((string) $template['event_key']) ?>]" value="1" <?= !empty($template['enabled']) ? 'checked' : '' ?>></td>
                        <td><strong><?= htmlspecialchars((string) $template['title']) ?></strong><br><small class="cf cm"><?= htmlspecialchars((string) $template['event_key']) ?></small></td>
                        <td><?= htmlspecialchars((string) $template['category']) ?></td>
                        <td class="cm"><?= htmlspecialchars(implode('، ', $names) ?: 'بدون متغیر') ?></td>
                        <td>
                            <select class="select" name="pattern[<?= htmlspecialchars((string) $template['event_key']) ?>]" style="min-width:260px">
                                <option value="">— بدون پترن —</option>
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
