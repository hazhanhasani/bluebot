<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/Logger.php';
require_once dirname(__DIR__) . '/Support/SmsService.php';

function claimPaymentPaid($order_id)
{
    global $pdo;

    $stmt = $pdo->prepare(
        "UPDATE Payment_report
         SET payment_Status = 'paid', at_updated = :at_updated
         WHERE id_order = :id_order
           AND COALESCE(payment_Status, '') NOT IN ('paid', 'delivery_error', 'delivery_reviewed')"
    );
    $stmt->bindValue(':id_order', $order_id);
    $stmt->bindValue(':at_updated', date('Y/m/d H:i:s'));
    $stmt->execute();

    if (function_exists('clearSelectCache')) {
        clearSelectCache('Payment_report');
    }

    $changed = $stmt->rowCount() >= 1;
    if ($changed) {
        bluebotAudit('payment.paid', [
            'order_id' => (string) $order_id,
        ]);

        try {
            $paymentStmt = $pdo->prepare('SELECT id_user,price,id_order FROM Payment_report WHERE id_order=? LIMIT 1');
            $paymentStmt->execute([$order_id]);
            $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (is_array($payment) && !empty($payment['id_user'])) {
                BluebotSms::queueForUser(
                    'payment_success',
                    (string) $payment['id_user'],
                    [
                        'amount' => (string) max(0, (int) $payment['price']),
                        'order_id' => mb_substr((string) $payment['id_order'], 0, 40),
                    ],
                    null,
                    (string) $payment['id_order'],
                    'payment-success:' . (string) $payment['id_order']
                );
            }
        } catch (Throwable $smsError) {
            bluebotLog('warning', 'Payment success SMS failed', [
                'order_id' => (string) $order_id,
                'error' => $smsError->getMessage(),
            ]);
        }
    }

    return $changed;
}

function markPaymentDeliveryError($order_id, $reason = '')
{
    global $pdo;

    $stmt = $pdo->prepare(
        "UPDATE Payment_report
         SET payment_Status = 'delivery_error', at_updated = :at_updated
         WHERE id_order = :id_order AND payment_Status = 'paid'"
    );
    $stmt->bindValue(':id_order', $order_id);
    $stmt->bindValue(':at_updated', date('Y/m/d H:i:s'));
    $stmt->execute();

    if (function_exists('clearSelectCache')) {
        clearSelectCache('Payment_report');
    }

    $changed = $stmt->rowCount() >= 1;
    if ($changed) {
        $reason = trim((string) $reason);
        $context = [
            'order_id' => (string) $order_id,
        ];
        if ($reason !== '') {
            $context['reason'] = $reason;
        }
        bluebotAudit('payment.delivery_error', $context);
    }

    return $changed;
}

function markPaymentDeliveryReviewed($order_id)
{
    global $pdo;

    $stmt = $pdo->prepare(
        "UPDATE Payment_report
         SET payment_Status = 'delivery_reviewed', at_updated = :at_updated
         WHERE id_order = :id_order AND payment_Status = 'delivery_error'"
    );
    $stmt->bindValue(':id_order', $order_id);
    $stmt->bindValue(':at_updated', date('Y/m/d H:i:s'));
    $stmt->execute();

    if (function_exists('clearSelectCache')) {
        clearSelectCache('Payment_report');
    }

    $changed = $stmt->rowCount() >= 1;
    if ($changed) {
        bluebotAudit('payment.delivery_reviewed', [
            'order_id' => (string) $order_id,
        ]);
    }

    return $changed;
}
