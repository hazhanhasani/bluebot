<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/Logger.php';

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
        bluebotLog('info', 'Payment marked paid', [
            'order_id' => (string) $order_id,
        ]);
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

    $reason = trim((string) $reason);
    if ($reason !== '') {
        bluebotLog('error', 'Payment service delivery failed', [
            'order_id' => (string) $order_id,
            'reason' => $reason,
        ]);
    }

    return $stmt->rowCount() >= 1;
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
        bluebotLog('audit', 'Payment delivery marked reviewed', [
            'order_id' => (string) $order_id,
        ]);
    }

    return $changed;
}
