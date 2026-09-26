<?php

declare(strict_types=1);

function bluebotBlupalBaseUrl(): string
{
    $override = trim((string) getenv('BLUEBOT_BLUPAL_BASE_URL'));
    if ($override !== '') {
        $parts = parse_url($override);
        if (is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https') {
            return rtrim($override, '/');
        }
    }

    return 'https://blupal.top/api';
}

function bluebotBlupalApiKey(): string
{
    return trim((string) getPaySettingValue('blupal_api_key', ''));
}

function bluebotBlupalConfigured(): bool
{
    $key = bluebotBlupalApiKey();
    return $key !== '' && $key !== '0';
}

function bluebotBlupalMode(): ?string
{
    $key = bluebotBlupalApiKey();
    if ($key === '' || $key === '0') {
        return null;
    }

    return str_starts_with($key, 'blu_test_') ? 'sandbox' : 'live';
}

function bluebotBlupalRequest(string $method, string $path, ?array $payload = null): array
{
    $apiKey = bluebotBlupalApiKey();
    if ($apiKey === '' || $apiKey === '0') {
        return ['success' => false, 'error' => 'blupal_not_configured'];
    }

    $url = bluebotBlupalBaseUrl() . '/' . ltrim($path, '/');
    $curl = curl_init($url);
    if ($curl === false) {
        return ['success' => false, 'error' => 'curl_init_failed'];
    }

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-API-Key: ' . $apiKey,
    ];

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'BlueBot-Blupal/1.0',
    ]);

    if ($payload !== null) {
        curl_setopt(
            $curl,
            CURLOPT_POSTFIELDS,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    $raw = curl_exec($curl);
    $error = $raw === false ? curl_error($curl) : '';
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($raw === false) {
        bluebotLog('warning', 'Blupal request failed', [
            'path' => $path,
            'http_code' => $status,
            'error' => $error,
        ]);
        return ['success' => false, 'error' => $error !== '' ? $error : 'request_failed'];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        bluebotLog('warning', 'Blupal returned invalid JSON', [
            'path' => $path,
            'http_code' => $status,
        ]);
        return ['success' => false, 'error' => 'invalid_response', 'http_code' => $status];
    }

    if ($status < 200 || $status >= 300 || (($data['success'] ?? true) === false)) {
        $message = (string) ($data['message'] ?? $data['error'] ?? 'blupal_request_failed');
        bluebotLog('warning', 'Blupal rejected request', [
            'path' => $path,
            'http_code' => $status,
            'error' => $message,
        ]);
        return [
            'success' => false,
            'error' => $message,
            'http_code' => $status,
            'response' => $data,
        ];
    }

    return $data + ['success' => true];
}

function bluebotBlupalCreateInvoice(int $amountToman, ?string $cardNumber = null): array
{
    // Blupal documents amounts in Rial and currently requires at least 100,000 Rial.
    if ($amountToman < 10000) {
        return ['success' => false, 'error' => 'amount_too_low'];
    }

    $payload = ['amount' => $amountToman * 10];

    $cardNumber = preg_replace('/\D+/', '', (string) $cardNumber);
    if (is_string($cardNumber) && strlen($cardNumber) === 16) {
        $payload['card_number'] = $cardNumber;
    }

    return bluebotBlupalRequest('POST', '/v1/invoices/create', $payload);
}

function bluebotBlupalGetInvoice(string $invoiceId): array
{
    $invoiceId = trim($invoiceId);
    if ($invoiceId === '' || !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $invoiceId)) {
        return ['success' => false, 'error' => 'invalid_invoice_id'];
    }

    return bluebotBlupalRequest('GET', '/v1/invoices/' . rawurlencode($invoiceId));
}

function bluebotBlupalFindPaymentByInvoice(string $invoiceId): ?array
{
    $payment = select('Payment_report', '*', 'dec_not_confirmed', $invoiceId, 'select');
    if (!is_array($payment) || (string) ($payment['Payment_Method'] ?? '') !== 'blupal') {
        return null;
    }

    return $payment;
}

function bluebotBlupalSettle(string $invoiceId): array
{
    $payment = bluebotBlupalFindPaymentByInvoice($invoiceId);
    if (!$payment) {
        return ['ok' => false, 'state' => 'not_found'];
    }

    if ((string) ($payment['payment_Status'] ?? '') === 'paid') {
        return ['ok' => true, 'state' => 'already_paid', 'order_id' => $payment['id_order']];
    }

    $remote = bluebotBlupalGetInvoice($invoiceId);
    if (empty($remote['success'])) {
        return ['ok' => false, 'state' => 'verify_failed', 'remote' => $remote];
    }

    $status = strtoupper((string) ($remote['status'] ?? ''));
    if ($status !== 'PAID') {
        return ['ok' => false, 'state' => strtolower($status !== '' ? $status : 'pending'), 'remote' => $remote];
    }

    $expectedRial = ((int) ($payment['price'] ?? 0)) * 10;
    if (isset($remote['amount']) && is_numeric($remote['amount']) && (int) $remote['amount'] !== $expectedRial) {
        bluebotLog('warning', 'Blupal amount mismatch', [
            'order_id' => (string) ($payment['id_order'] ?? ''),
            'invoice_id' => $invoiceId,
            'expected_rial' => $expectedRial,
            'remote_rial' => (int) $remote['amount'],
        ]);
        return ['ok' => false, 'state' => 'amount_mismatch', 'remote' => $remote];
    }

    $orderId = (string) $payment['id_order'];
    if (!claimPaymentPaid($orderId)) {
        $latest = select('Payment_report', '*', 'id_order', $orderId, 'select');
        $state = is_array($latest) ? (string) ($latest['payment_Status'] ?? '') : '';
        return [
            'ok' => $state === 'paid',
            'state' => $state === 'paid' ? 'already_paid' : ($state !== '' ? $state : 'already_claimed'),
            'order_id' => $orderId,
            'remote' => $remote,
        ];
    }

    try {
        DirectPayment($orderId, __DIR__ . '/../../images.jpg');
    } catch (Throwable $error) {
        markPaymentDeliveryError($orderId, $error->getMessage());
        bluebotLog('error', 'Blupal payment delivery failed', [
            'order_id' => $orderId,
            'invoice_id' => $invoiceId,
            'error' => $error->getMessage(),
        ]);
        return ['ok' => false, 'state' => 'delivery_error', 'order_id' => $orderId, 'remote' => $remote];
    }

    $cashback = (float) getPaySettingValue('chashbackblupal', '0');
    if ($cashback > 0) {
        $buyer = select('user', '*', 'id', $payment['id_user'], 'select');
        $reward = ((float) $payment['price'] * $cashback) / 100;
        if (is_array($buyer) && $reward > 0) {
            addBalance($buyer['id'], $reward);
            $lang = languagechange();
            $giftText = sprintf($lang['paymentGateway']['giftReport'], number_format($reward));
            sendmessage($buyer['id'], $giftText, null, 'HTML');
        }
    }

    $setting = select('setting', '*');
    $topic = select('topicid', 'idreport', 'report', 'paymentreport', 'select');
    if (is_array($setting) && !empty($setting['Channel_Report']) && is_array($topic)) {
        $buyer = select('user', '*', 'id', $payment['id_user'], 'select');
        $lang = languagechange();
        $reportTemplate = $lang['paymentGateway']['reportBlupal']
            ?? "💵 Blupal payment\nUser: %s\nID: %s\nAmount: %s\nInvoice: %s";
        $report = sprintf(
            $reportTemplate,
            $buyer['username'] ?? '-',
            $payment['id_user'],
            number_format((float) $payment['price']),
            $invoiceId
        );
        telegram('sendmessage', [
            'chat_id' => $setting['Channel_Report'],
            'message_thread_id' => $topic['idreport'] ?? null,
            'text' => $report,
            'parse_mode' => 'HTML',
        ]);
    }

    return ['ok' => true, 'state' => 'paid', 'order_id' => $orderId, 'remote' => $remote];
}
