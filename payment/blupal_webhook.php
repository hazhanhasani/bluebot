<?php

declare(strict_types=1);

ini_set('error_log', 'error_log');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/../src/Support/JalaliDate.php';
require __DIR__ . '/../vendor/autoload.php';

$ManagePanel = new ManagePanel();
$textbotlang = languagechange();

function blupalWebhookRespond(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(['status' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    blupalWebhookRespond(405, 'method_not_allowed');
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || trim($raw) === '') {
    blupalWebhookRespond(400, 'empty_body');
}

if (strlen($raw) > 65536) {
    blupalWebhookRespond(413, 'payload_too_large');
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    blupalWebhookRespond(400, 'invalid_json');
}

$event = trim((string) ($payload['event'] ?? ''));
$status = strtoupper(trim((string) ($payload['status'] ?? '')));
$invoiceId = trim((string) ($payload['invoice_id'] ?? ''));

if ($event !== '' && $event !== 'payment.completed') {
    blupalWebhookRespond(200, 'ignored_event');
}

if ($status !== 'PAID') {
    blupalWebhookRespond(200, 'ignored_status');
}

if ($invoiceId === '' || !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $invoiceId)) {
    blupalWebhookRespond(400, 'invalid_invoice');
}

$payment = bluebotBlupalFindPaymentByInvoice($invoiceId);
if (!$payment) {
    bluebotLog('warning', 'Blupal webhook invoice not found', [
        'invoice_id' => $invoiceId,
    ]);
    blupalWebhookRespond(404, 'invoice_not_found');
}

if ((string) ($payment['payment_Status'] ?? '') === 'paid') {
    blupalWebhookRespond(200, 'already_paid');
}

/*
 * The incoming webhook is only a trigger. BlueBot never trusts its payment
 * fields directly: the invoice is fetched from Blupal over its authenticated
 * API and amount/status are verified before the local order is claimed.
 */
$result = bluebotBlupalSettle($invoiceId);
$state = (string) ($result['state'] ?? 'unknown');

if (!empty($result['ok'])) {
    blupalWebhookRespond(200, $state === 'already_paid' ? 'already_paid' : 'ok');
}

if ($state === 'delivery_error') {
    blupalWebhookRespond(500, 'delivery_error');
}

if ($state === 'verify_failed') {
    blupalWebhookRespond(503, 'verification_unavailable');
}

if ($state === 'amount_mismatch') {
    blupalWebhookRespond(409, 'amount_mismatch');
}

if (in_array($state, ['pending', 'canceled', 'expired'], true)) {
    blupalWebhookRespond(409, $state);
}

blupalWebhookRespond(400, $state !== '' ? $state : 'not_verified');
