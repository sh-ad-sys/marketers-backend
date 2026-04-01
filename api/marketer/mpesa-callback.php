<?php
/**
 * MPesa STK callback endpoint for property payments.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../shared/storage.php';

function propertyPaymentCallbackMetadata(array $callback): array
{
    $items = $callback['CallbackMetadata']['Item'] ?? [];
    if (!is_array($items)) {
        return [];
    }

    $metadata = [];
    foreach ($items as $item) {
        if (!is_array($item) || !isset($item['Name'])) {
            continue;
        }

        $metadata[(string)$item['Name']] = $item['Value'] ?? null;
    }

    return $metadata;
}

try {
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $callback = $payload['Body']['stkCallback'] ?? [];

    $merchantRequestId = trim((string)($callback['MerchantRequestID'] ?? ''));
    $checkoutRequestId = trim((string)($callback['CheckoutRequestID'] ?? ''));
    $resultCode = isset($callback['ResultCode']) ? (int)$callback['ResultCode'] : -1;
    $resultDesc = trim((string)($callback['ResultDesc'] ?? 'MPesa callback received'));
    $metadata = propertyPaymentCallbackMetadata($callback);

    $amount = isset($metadata['Amount']) ? (float)$metadata['Amount'] : null;
    $receiptNumber = trim((string)($metadata['MpesaReceiptNumber'] ?? ''));
    $paymentPhone = apiNormalizeKenyanPhone((string)($metadata['PhoneNumber'] ?? ''));
    $paidAt = apiMpesaTimestampToMySql((string)($metadata['TransactionDate'] ?? '')) ?: date('Y-m-d H:i:s');

    if ($merchantRequestId === '' && $checkoutRequestId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing MPesa request identifiers']);
        exit;
    }

    if (apiUsesMongoStorage()) {
        require_once __DIR__ . '/../mongo/config.php';

        $db = mongoDb();
        $property = $db->properties->findOne([
            '$or' => [
                ['checkout_request_id' => $checkoutRequestId],
                ['merchant_request_id' => $merchantRequestId],
            ],
        ]);

        if (!$property) {
            echo json_encode(['success' => false, 'message' => 'Property payment not found']);
            exit;
        }

        $update = [
            'payment_result_desc' => $resultDesc,
            'payment_phone' => $paymentPhone !== '' ? $paymentPhone : (string)($property['payment_phone'] ?? ''),
            'payment_amount' => $amount ?? ($property['payment_amount'] ?? null),
            'merchant_request_id' => $merchantRequestId !== '' ? $merchantRequestId : (string)($property['merchant_request_id'] ?? ''),
            'checkout_request_id' => $checkoutRequestId !== '' ? $checkoutRequestId : (string)($property['checkout_request_id'] ?? ''),
        ];

        if ($resultCode === 0) {
            $update['payment_status'] = 'completed';
            $update['mpesa_receipt_number'] = $receiptNumber;
            $update['paid_at'] = mongoDateFromValue($paidAt) ?? mongoNow();
            $update['status'] = 'approved';
        } else {
            $update['payment_status'] = 'failed';
        }

        $db->properties->updateOne(['_id' => normalizeId($property['_id'] ?? 0)], ['$set' => $update]);

        echo json_encode(['success' => true, 'message' => 'Callback processed']);
        exit;
    }

    $conn = apiMysql();
    apiEnsurePropertiesSchema($conn);

    $stmt = $conn->prepare('
        SELECT *
        FROM properties
        WHERE checkout_request_id = ? OR merchant_request_id = ?
        LIMIT 1
    ');
    $stmt->execute([$checkoutRequestId, $merchantRequestId]);
    $property = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$property) {
        echo json_encode(['success' => false, 'message' => 'Property payment not found']);
        exit;
    }

    $paymentAmount = $amount ?? (isset($property['payment_amount']) ? (float)$property['payment_amount'] : null);
    $storedPhone = $paymentPhone !== '' ? $paymentPhone : (string)($property['payment_phone'] ?? '');

    if ($resultCode === 0) {
        $updateStmt = $conn->prepare('
            UPDATE properties
            SET payment_status = ?, payment_amount = ?, payment_phone = ?, mpesa_receipt_number = ?,
                payment_result_desc = ?, paid_at = ?, status = ?, merchant_request_id = ?, checkout_request_id = ?
            WHERE id = ?
        ');
        $updateStmt->execute([
            'completed',
            $paymentAmount,
            $storedPhone,
            $receiptNumber,
            $resultDesc,
            $paidAt,
            'approved',
            $merchantRequestId !== '' ? $merchantRequestId : (string)($property['merchant_request_id'] ?? ''),
            $checkoutRequestId !== '' ? $checkoutRequestId : (string)($property['checkout_request_id'] ?? ''),
            (int)$property['id'],
        ]);
    } else {
        $updateStmt = $conn->prepare('
            UPDATE properties
            SET payment_status = ?, payment_amount = ?, payment_phone = ?, payment_result_desc = ?,
                merchant_request_id = ?, checkout_request_id = ?
            WHERE id = ?
        ');
        $updateStmt->execute([
            'failed',
            $paymentAmount,
            $storedPhone,
            $resultDesc,
            $merchantRequestId !== '' ? $merchantRequestId : (string)($property['merchant_request_id'] ?? ''),
            $checkoutRequestId !== '' ? $checkoutRequestId : (string)($property['checkout_request_id'] ?? ''),
            (int)$property['id'],
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Callback processed']);
} catch (Throwable $e) {
    error_log('MPesa callback error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Callback processing failed']);
}
