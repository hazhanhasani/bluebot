<?php

declare(strict_types=1);

// Load the complete production service from a sandbox so its key storage never
// touches a real installation. SQLite executes the actual OTP database queries.
$root = dirname(__DIR__);
$sandbox = sys_get_temp_dir() . '/bluebot-sms-otp-test-' . bin2hex(random_bytes(6));
mkdir($sandbox . '/src/Support', 0700, true);
mkdir($sandbox . '/storage', 0700);
$key = random_bytes(32);
file_put_contents($sandbox . '/storage/sms.key', base64_encode($key));
copy($root . '/src/Support/SmsService.php', $sandbox . '/src/Support/SmsService.php');

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$reject = static function (string $userId, string $code, string $expected) use ($assert): void {
    try {
        BluebotSms::verifyPhoneOtp($userId, $code);
        $assert(false, 'OTP request should have been rejected: ' . $expected);
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), $expected), 'Unexpected OTP rejection: ' . $e->getMessage());
    }
};

try {
    require $sandbox . '/src/Support/SmsService.php';
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE sms_otp_challenges (
        id TEXT PRIMARY KEY, user_id TEXT NOT NULL, phone TEXT NOT NULL,
        code_hash TEXT NOT NULL, attempts INTEGER NOT NULL, max_attempts INTEGER NOT NULL,
        expires_at INTEGER NOT NULL, consumed_at INTEGER, created_at INTEGER NOT NULL
    )');
    $seed = static function (string $userId, string $code = '902017', int $expiresIn = 120) use ($pdo, $key): string {
        $id = 'challenge-' . $userId;
        $phone = '+989121234567';
        // Construct the stored hash independently of the production helper.
        $hash = hash_hmac('sha256', $id . ':' . $userId . ':' . $phone . ':' . $code, $key);
        $pdo->prepare('INSERT INTO sms_otp_challenges VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $userId, $phone, $hash, 0, 3, time() + $expiresIn, null, time()]);
        return $id;
    };
    $row = static function (string $id) use ($pdo): array {
        $stmt = $pdo->prepare('SELECT * FROM sms_otp_challenges WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    };

    $successCases = [
        ['902017', '902017'], ['۹۰۲۰۱۷', '902017'], ['٩٠٢٠١٧', '902017'],
        ['9۰٢0۱٧', '902017'], [' ۹۰۲۰۱۷ ', '902017'],
        ['۳۴۵۶۷۸', '345678'], ['٣٤٥٦٧٨', '345678'],
    ];
    foreach ($successCases as $index => [$input, $expectedCode]) {
        $userId = 'success-' . $index;
        $id = $seed($userId, $expectedCode);
        try {
            $result = BluebotSms::verifyPhoneOtp($userId, $input);
            $assert($result === ['ok' => true, 'phone' => '+989121234567'], 'Correct Unicode OTP must verify: ' . $input);
        } catch (RuntimeException $e) {
            $assert(false, 'Correct Unicode OTP failed (' . $input . '): ' . $e->getMessage());
        }
        $challenge = $row($id);
        $assert((int) $challenge['attempts'] === 1 && $challenge['consumed_at'] !== null,
            'Successful OTP must count one attempt and consume its challenge: ' . $input);
        if ($challenge['consumed_at'] !== null) {
            $reject($userId, $expectedCode, 'درخواست تأیید شماره پیدا نشد');
            $assert($row($id) === $challenge, 'Replaying a consumed OTP must not change its challenge.');
        }
    }

    $id = $seed('wrong-code');
    foreach (['۱۲۳۴۵۶', '١٢٣٤٥٦', '123456'] as $index => $input) {
        $reject('wrong-code', $input, $index < 2 ? 'کد تأیید نادرست است' : 'تعداد تلاش‌های ناموفق تمام شد');
        $challenge = $row($id);
        $assert((int) $challenge['attempts'] === $index + 1, 'Every incorrect six-digit OTP must count against the attempt limit.');
        $assert(($challenge['consumed_at'] !== null) === ($index === 2), 'Only the final unsuccessful attempt must consume the challenge.');
    }
    $exhausted = $row($id);
    $reject('wrong-code', '902017', 'درخواست تأیید شماره پیدا نشد');
    $assert($row($id) === $exhausted, 'An exhausted challenge must reject even its correct code.');

    $id = $seed('expired-code', '902017', -1);
    $reject('expired-code', '۹۰۲۰۱۷', 'مهلت کد تأیید تمام شده است');
    $assert($row($id)['consumed_at'] !== null && (int) $row($id)['attempts'] === 0,
        'Expired Unicode OTP must be consumed without testing its hash or counting an attempt.');
    $reject('expired-code', '902017', 'درخواست تأیید شماره پیدا نشد');

    $id = $seed('length-check');
    foreach (['۹۰۲۰۱', '٩٠٢٠١٧٨', 'letters'] as $input) {
        $reject('length-check', $input, 'کد تأیید باید دقیقاً ۶ رقم باشد');
    }
    $assert((int) $row($id)['attempts'] === 0 && $row($id)['consumed_at'] === null,
        'Invalid OTP lengths must retain existing validation behavior.');
    $reject('another-user', '902017', 'درخواست تأیید شماره پیدا نشد');
    $assert((int) $row($id)['attempts'] === 0, 'An OTP challenge must remain bound to its user.');

    $cleanParams = new ReflectionMethod(BluebotSms::class, 'cleanParams');
    $normalizeLine = new ReflectionMethod(BluebotSms::class, 'normalizeLine');
    foreach (['۹۰۲۰۱۷', '٩٠٢٠١٧', '9۰٢0۱٧'] as $input) {
        try {
            $params = $cleanParams->invoke(null, BluebotSms::catalog()['phone_verification'], ['code' => $input]);
            $assert($params === ['code' => '902017'], 'Numeric SMS parameters must normalize Unicode digits before length limits.');
        } catch (RuntimeException $e) {
            $assert(false, 'Numeric Unicode SMS parameters failed: ' . $e->getMessage());
        }
        $line = $normalizeLine->invoke(null, ['line_number' => $input, 'status' => 'active']);
        $assert(($line['number'] ?? '') === '902017', 'Sender line discovery must normalize Unicode digits.');
    }
    foreach (['۰۹۱۲۱۲۳۴۵۶۷', '٠٩١٢١٢٣٤٥٦٧', '+98۹١۲1234567'] as $phone) {
        $assert(BluebotSms::normalizePhone($phone) === '+989121234567', 'Phone digit normalization must remain compatible: ' . $phone);
    }
} finally {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($sandbox);
}

if ($failures !== []) {
    fwrite(STDERR, "SMS OTP tests failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "SMS Unicode digits and OTP verification tests OK.\n";
