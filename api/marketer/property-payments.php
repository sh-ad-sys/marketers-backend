<?php
/**
 * Initiate MPesa STK push for a property package payment.
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

$response = ['success' => false, 'message' => ''];

function propertyPaymentResolveMongoMarketerId($db): int
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

function propertyPaymentReference(int $propertyId): string
{
    return 'PCPROP' . $propertyId;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    $data = apiJsonInput();
    $propertyId = (int)($data['property_id'] ?? 0);
    $mpesaPhone = apiNormalizeKenyanPhone((string)($data['phone'] ?? ''));

    if ($propertyId <= 0 || $mpesaPhone === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Property ID and MPesa phone are required']);
        exit;
    }

    if (!preg_match('/^254\d{9}$/', $mpesaPhone)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Enter a valid Kenyan MPesa phone number']);
        exit;
    }

    $config = apiMpesaConfig(true);
    $configError = apiMpesaValidateConfig($config, true);
    if ($configError !== null) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $configError]);
        exit;
    }

    $timestamp = apiMpesaTimestamp();
    $password = apiMpesaPassword($config, $timestamp);

    if (apiUsesMongoStorage()) {
        require_once __DIR__ . '/../mongo/config.php';
        ensureSession();

        $db = mongoDb();
        $marketerId = propertyPaymentResolveMongoMarketerId($db);
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

        if (($property['payment_status'] ?? 'unpaid') === 'completed' || ($property['status'] ?? '') === 'approved') {
            echo json_encode(['success' => false, 'message' => 'This property has already been paid for']);
            exit;
        }

        if (($property['payment_status'] ?? '') === 'initiated') {
            echo json_encode(['success' => false, 'message' => 'A payment request is already awaiting confirmation for this property']);
            exit;
        }

        $amount = apiResolvePackageAmount((string)($property['package_selected'] ?? ''));
        if ($amount === null) {
            echo json_encode(['success' => false, 'message' => 'Unknown package selected for this property']);
            exit;
        }

        $reference = propertyPaymentReference($propertyId);
        $accessToken = apiMpesaAccessToken($config);
        $stkResponse = apiMpesaInitiateStk($config, $accessToken, [
            'BusinessShortCode' => $config['shortcode'],
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => $config['transaction_type'],
            'Amount' => (int)$amount,
            'PartyA' => $mpesaPhone,
            'PartyB' => $config['shortcode'],
            'PhoneNumber' => $mpesaPhone,
            'CallBackURL' => $config['callback_url'],
            'AccountReference' => $reference,
            'TransactionDesc' => 'Property package payment',
        ]);

        $responseCode = (string)($stkResponse['json']['ResponseCode'] ?? '');
        $resultMessage = apiMpesaResponseMessage($stkResponse, 'Unable to initiate MPesa STK push');
        if ($stkResponse['status'] >= 400 || $responseCode !== '0') {
            $db->properties->updateOne(
                ['_id' => $propertyId],
                ['$set' => [
                    'payment_status' => 'failed',
                    'payment_amount' => (float)$amount,
                    'payment_phone' => $mpesaPhone,
                    'payment_result_desc' => $resultMessage,
                ]]
            );
            echo json_encode(['success' => false, 'message' => $resultMessage]);
            exit;
        }

        $db->properties->updateOne(
            ['_id' => $propertyId],
            ['$set' => [
                'payment_status' => 'initiated',
                'payment_amount' => (float)$amount,
                'payment_phone' => $mpesaPhone,
                'checkout_request_id' => (string)($stkResponse['json']['CheckoutRequestID'] ?? ''),
                'merchant_request_id' => (string)($stkResponse['json']['MerchantRequestID'] ?? ''),
                'payment_reference' => $reference,
                'payment_result_desc' => $resultMessage,
                'payment_requested_at' => mongoNow(),
            ]]
        );

        echo json_encode([
            'success' => true,
            'message' => $resultMessage,
            'data' => [
                'property_id' => $propertyId,
                'payment_status' => 'initiated',
                'amount' => (float)$amount,
                'phone' => $mpesaPhone,
                'checkout_request_id' => (string)($stkResponse['json']['CheckoutRequestID'] ?? ''),
                'merchant_request_id' => (string)($stkResponse['json']['MerchantRequestID'] ?? ''),
            ],
        ]);
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

    if (($property['payment_status'] ?? 'unpaid') === 'completed' || ($property['status'] ?? '') === 'approved') {
        echo json_encode(['success' => false, 'message' => 'This property has already been paid for']);
        exit;
    }

    if (($property['payment_status'] ?? '') === 'initiated') {
        echo json_encode(['success' => false, 'message' => 'A payment request is already awaiting confirmation for this property']);
        exit;
    }

    $amount = apiResolvePackageAmount((string)($property['package_selected'] ?? ''));
    if ($amount === null) {
        echo json_encode(['success' => false, 'message' => 'Unknown package selected for this property']);
        exit;
    }

    $reference = propertyPaymentReference($propertyId);
    $accessToken = apiMpesaAccessToken($config);
    $stkResponse = apiMpesaInitiateStk($config, $accessToken, [
        'BusinessShortCode' => $config['shortcode'],
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => $config['transaction_type'],
        'Amount' => (int)$amount,
        'PartyA' => $mpesaPhone,
        'PartyB' => $config['shortcode'],
        'PhoneNumber' => $mpesaPhone,
        'CallBackURL' => $config['callback_url'],
        'AccountReference' => $reference,
        'TransactionDesc' => 'Property package payment',
    ]);

    $responseCode = (string)($stkResponse['json']['ResponseCode'] ?? '');
    $resultMessage = apiMpesaResponseMessage($stkResponse, 'Unable to initiate MPesa STK push');
    $updateStmt = $conn->prepare('
        UPDATE properties
        SET payment_status = ?, payment_amount = ?, payment_phone = ?, checkout_request_id = ?,
            merchant_request_id = ?, payment_reference = ?, payment_result_desc = ?, payment_requested_at = ?
        WHERE id = ?
    ');

    if ($stkResponse['status'] >= 400 || $responseCode !== '0') {
        $updateStmt->execute([
            'failed',
            (float)$amount,
            $mpesaPhone,
            '',
            '',
            $reference,
            $resultMessage,
            date('Y-m-d H:i:s'),
            $propertyId,
        ]);
        echo json_encode(['success' => false, 'message' => $resultMessage]);
        exit;
    }

    $updateStmt->execute([
        'initiated',
        (float)$amount,
        $mpesaPhone,
        (string)($stkResponse['json']['CheckoutRequestID'] ?? ''),
        (string)($stkResponse['json']['MerchantRequestID'] ?? ''),
        $reference,
        $resultMessage,
        date('Y-m-d H:i:s'),
        $propertyId,
    ]);

    echo json_encode([
        'success' => true,
        'message' => $resultMessage,
        'data' => [
            'property_id' => $propertyId,
            'payment_status' => 'initiated',
            'amount' => (float)$amount,
            'phone' => $mpesaPhone,
            'checkout_request_id' => (string)($stkResponse['json']['CheckoutRequestID'] ?? ''),
            'merchant_request_id' => (string)($stkResponse['json']['MerchantRequestID'] ?? ''),
        ],
    ]);
} catch (Throwable $e) {
    error_log('Property payment initiation error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to initiate MPesa payment: ' . $e->getMessage(),
    ]);
}
