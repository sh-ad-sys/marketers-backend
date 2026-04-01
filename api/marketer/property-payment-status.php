<?php
/**
 * Sync Daraja STK payment status for a property package payment.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/mpesa.php';
apiEnsureSessionStarted();

function propertyPaymentStatusResolveMongoMarketerId($db): int
{
    $marketerId = isset($_SERVER['HTTP_X_AUTH_MARKETER_ID']) ? (int)$_SERVER['HTTP_X_AUTH_MARKETER_ID'] : 0;
    if ($marketerId <= 0 && isset($_SESSION['marketer_id'])) {
        $marketerId = normalizeId($_SESSION['marketer_id']);
    }
    if ($marketerId <= 0) {
        $username = $_SERVER['HTTP_X_AUTH_USER'] ?? '';
        if ($username !== '') {
            $marketer = $db->marketers->findOne(
                ['$or' => [['name' => $username], ['email' => $username]]],
                ['projection' => ['_id' => 1]]
            );
            if ($marketer) {
                $marketerId = normalizeId($marketer['_id']);
            }
        }
    }

    return $marketerId;
}

function propertyPaymentStatusResponsePayload(array $property, string $message): array
{
    $propertyId = isset($property['id'])
        ? (int)$property['id']
        : (function_exists('normalizeId') ? normalizeId($property['_id'] ?? 0) : 0);
    $paymentRequestedAt = $property['payment_requested_at'] ?? null;
    $paidAt = $property['paid_at'] ?? null;

    if (function_exists('mongoDateToString')) {
        $paymentRequestedAt = mongoDateToString($paymentRequestedAt) ?: $paymentRequestedAt;
        $paidAt = mongoDateToString($paidAt) ?: $paidAt;
    }

    return [
        'success' => true,
        'message' => $message,
        'data' => [
            'property_id' => $propertyId,
            'payment_status' => (string)($property['payment_status'] ?? 'unpaid'),
            'payment_result_desc' => (string)($property['payment_result_desc'] ?? ''),
            'payment_phone' => (string)($property['payment_phone'] ?? ''),
            'payment_amount' => isset($property['payment_amount']) ? (float)$property['payment_amount'] : null,
            'checkout_request_id' => (string)($property['checkout_request_id'] ?? ''),
            'merchant_request_id' => (string)($property['merchant_request_id'] ?? ''),
            'mpesa_receipt_number' => (string)($property['mpesa_receipt_number'] ?? ''),
            'payment_requested_at' => $paymentRequestedAt,
            'paid_at' => $paidAt,
            'status' => (string)($property['status'] ?? 'pending'),
        ],
    ];
}

function propertyPaymentStatusTerminalMessage(int $resultCode, string $resultDesc): string
{
    if ($resultCode === 0) {
        return $resultDesc !== '' ? $resultDesc : 'MPesa payment confirmed successfully.';
    }

    if ($resultCode === 2029) {
        return 'MPesa returned code 2029. The STK request was created, but Safaricom could not resolve it. Check that the live shortcode, passkey, and transaction type match the same Daraja account.';
    }

    return $resultDesc !== '' ? $resultDesc : 'MPesa payment was not completed.';
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    $data = apiJsonInput();
    $propertyId = (int)($data['property_id'] ?? 0);

    if ($propertyId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Property ID is required']);
        exit;
    }

    $config = apiMpesaConfig(false);
    $configError = apiMpesaValidateConfig($config, false);
    if ($configError !== null) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $configError]);
        exit;
    }

    if (apiUsesMongoStorage()) {
        require_once __DIR__ . '/../mongo/config.php';
        ensureSession();

        $db = mongoDb();
        $marketerId = propertyPaymentStatusResolveMongoMarketerId($db);
        if ($marketerId <= 0) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Authentication required']);
            exit;
        }

        $property = $db->properties->findOne(['_id' => $propertyId, 'marketer_id' => $marketerId]);
        if (!$property) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Property not found']);
            exit;
        }

        $currentStatus = strtolower(trim((string)($property['payment_status'] ?? 'unpaid')));
        if ($currentStatus !== 'initiated') {
            echo json_encode(propertyPaymentStatusResponsePayload((array)$property, 'Payment status is already up to date.'));
            exit;
        }

        $checkoutRequestId = trim((string)($property['checkout_request_id'] ?? ''));
        if ($checkoutRequestId === '') {
            echo json_encode(propertyPaymentStatusResponsePayload((array)$property, 'The payment request has not been sent to Daraja yet.'));
            exit;
        }

        $accessToken = apiMpesaAccessToken($config);
        $queryResponse = apiMpesaQueryStk($config, $accessToken, $checkoutRequestId);

        $responseCode = (string)($queryResponse['json']['ResponseCode'] ?? '');
        $resultCode = array_key_exists('ResultCode', $queryResponse['json']) ? (int)$queryResponse['json']['ResultCode'] : null;
        $resultDesc = trim((string)($queryResponse['json']['ResultDesc'] ?? ($queryResponse['json']['ResponseDescription'] ?? '')));

        if ($queryResponse['status'] >= 400 || ($responseCode !== '' && $responseCode !== '0' && $resultCode === null)) {
            echo json_encode([
                'success' => false,
                'message' => apiMpesaResponseMessage($queryResponse, 'Unable to check MPesa payment status'),
            ]);
            exit;
        }

        if ($resultCode === null) {
            $db->properties->updateOne(
                ['_id' => $propertyId],
                ['$set' => ['payment_result_desc' => $resultDesc !== '' ? $resultDesc : 'Payment is still pending confirmation']]
            );

            $property['payment_result_desc'] = $resultDesc !== '' ? $resultDesc : 'Payment is still pending confirmation';
            echo json_encode(propertyPaymentStatusResponsePayload((array)$property, 'Payment is still pending confirmation.'));
            exit;
        }

        $update = [
            'payment_result_desc' => propertyPaymentStatusTerminalMessage($resultCode, $resultDesc),
        ];

        if ($resultCode === 0) {
            $update['payment_status'] = 'completed';
            $update['status'] = 'approved';
            if (empty($property['paid_at'])) {
                $update['paid_at'] = mongoNow();
            }
        } else {
            $update['payment_status'] = 'failed';
        }

        $db->properties->updateOne(['_id' => $propertyId], ['$set' => $update]);
        $property = $db->properties->findOne(['_id' => $propertyId]) ?: $property;

        echo json_encode(propertyPaymentStatusResponsePayload((array)$property, $update['payment_result_desc']));
        exit;
    }

    $conn = apiMysql();
    apiEnsurePropertiesSchema($conn);

    $marketerId = apiResolveMySqlMarketerId($conn);
    if ($marketerId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required']);
        exit;
    }

    $stmt = $conn->prepare('SELECT * FROM properties WHERE id = ? AND marketer_id = ? LIMIT 1');
    $stmt->execute([$propertyId, $marketerId]);
    $property = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$property) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Property not found']);
        exit;
    }

    $currentStatus = strtolower(trim((string)($property['payment_status'] ?? 'unpaid')));
    if ($currentStatus !== 'initiated') {
        echo json_encode(propertyPaymentStatusResponsePayload($property, 'Payment status is already up to date.'));
        exit;
    }

    $checkoutRequestId = trim((string)($property['checkout_request_id'] ?? ''));
    if ($checkoutRequestId === '') {
        echo json_encode(propertyPaymentStatusResponsePayload($property, 'The payment request has not been sent to Daraja yet.'));
        exit;
    }

    $accessToken = apiMpesaAccessToken($config);
    $queryResponse = apiMpesaQueryStk($config, $accessToken, $checkoutRequestId);

    $responseCode = (string)($queryResponse['json']['ResponseCode'] ?? '');
    $resultCode = array_key_exists('ResultCode', $queryResponse['json']) ? (int)$queryResponse['json']['ResultCode'] : null;
    $resultDesc = trim((string)($queryResponse['json']['ResultDesc'] ?? ($queryResponse['json']['ResponseDescription'] ?? '')));

    if ($queryResponse['status'] >= 400 || ($responseCode !== '' && $responseCode !== '0' && $resultCode === null)) {
        echo json_encode([
            'success' => false,
            'message' => apiMpesaResponseMessage($queryResponse, 'Unable to check MPesa payment status'),
        ]);
        exit;
    }

    if ($resultCode === null) {
        $pendingMessage = $resultDesc !== '' ? $resultDesc : 'Payment is still pending confirmation';
        $updateStmt = $conn->prepare('
            UPDATE properties
            SET payment_result_desc = ?
            WHERE id = ?
        ');
        $updateStmt->execute([$pendingMessage, $propertyId]);
        $property['payment_result_desc'] = $pendingMessage;

        echo json_encode(propertyPaymentStatusResponsePayload($property, 'Payment is still pending confirmation.'));
        exit;
    }

    $message = propertyPaymentStatusTerminalMessage($resultCode, $resultDesc);
    if ($resultCode === 0) {
        $updateStmt = $conn->prepare('
            UPDATE properties
            SET payment_status = ?, payment_result_desc = ?, status = ?, paid_at = COALESCE(paid_at, ?)
            WHERE id = ?
        ');
        $updateStmt->execute([
            'completed',
            $message,
            'approved',
            date('Y-m-d H:i:s'),
            $propertyId,
        ]);
    } else {
        $updateStmt = $conn->prepare('
            UPDATE properties
            SET payment_status = ?, payment_result_desc = ?
            WHERE id = ?
        ');
        $updateStmt->execute([
            'failed',
            $message,
            $propertyId,
        ]);
    }

    $stmt = $conn->prepare('SELECT * FROM properties WHERE id = ? LIMIT 1');
    $stmt->execute([$propertyId]);
    $updatedProperty = $stmt->fetch(PDO::FETCH_ASSOC) ?: $property;

    echo json_encode(propertyPaymentStatusResponsePayload($updatedProperty, $message));
} catch (Throwable $e) {
    error_log('Property payment status sync error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to sync MPesa payment status: ' . $e->getMessage(),
    ]);
}
