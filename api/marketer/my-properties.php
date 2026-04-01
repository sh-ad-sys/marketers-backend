<?php
/**
 * My Properties API
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../shared/storage.php';
apiEnsureSessionStarted();

$response = ['success' => false, 'message' => '', 'data' => []];

try {
    if (apiUsesMongoStorage()) {
        require_once __DIR__ . '/../mongo/config.php';
        ensureSession();

        $db = mongoDb();
        $marketerId = isset($_SERVER['HTTP_X_AUTH_MARKETER_ID']) ? (int)$_SERVER['HTTP_X_AUTH_MARKETER_ID'] : 0;

        if ($marketerId <= 0 && isset($_SESSION['marketer_id'])) {
            $marketerId = normalizeId($_SESSION['marketer_id']);
        }

        if ($marketerId <= 0) {
            $username = $_SERVER['HTTP_X_AUTH_USER'] ?? '';
            if ($username !== '') {
                $mk = $db->marketers->findOne(['$or' => [['name' => $username], ['email' => $username]]], ['projection' => ['_id' => 1]]);
                if ($mk) {
                    $marketerId = normalizeId($mk['_id']);
                }
            }
        }

        if ($marketerId <= 0) {
            $response['message'] = 'Authentication required';
            echo json_encode($response);
            exit;
        }

        $properties = [];
        $cursor = $db->properties->find(['marketer_id' => $marketerId], ['sort' => ['created_at' => -1, '_id' => -1]]);
        foreach ($cursor as $p) {
            $properties[] = [
                'id' => normalizeId($p['_id'] ?? 0),
                'marketer_id' => normalizeId($p['marketer_id'] ?? 0),
                'owner_name' => (string)($p['owner_name'] ?? ''),
                'owner_email' => (string)($p['owner_email'] ?? ''),
                'phone' => (string)($p['phone_number_1'] ?? ($p['phone'] ?? ($p['phone_number'] ?? ''))),
                'phone_number' => (string)($p['phone_number_1'] ?? ($p['phone'] ?? ($p['phone_number'] ?? ''))),
                'phone_number_1' => (string)($p['phone_number_1'] ?? ($p['phone'] ?? ($p['phone_number'] ?? ''))),
                'phone_number_2' => (string)($p['phone_number_2'] ?? ''),
                'whatsapp_phone' => (string)($p['whatsapp_phone'] ?? ''),
                'property_name' => (string)($p['property_name'] ?? ''),
                'property_location' => (string)($p['property_location'] ?? ''),
                'property_type' => (string)($p['property_type'] ?? ''),
                'booking_type' => (string)($p['booking_type'] ?? ''),
                'package_selected' => (string)($p['package_selected'] ?? ''),
                'county' => (string)($p['county'] ?? ''),
                'area' => (string)($p['area'] ?? ''),
                'map_link' => (string)($p['map_link'] ?? ''),
                'status' => (string)($p['status'] ?? 'pending'),
                'payment_status' => (string)($p['payment_status'] ?? 'unpaid'),
                'payment_amount' => apiResolvedPropertyPaymentAmount($p),
                'payment_phone' => (string)($p['payment_phone'] ?? ''),
                'checkout_request_id' => (string)($p['checkout_request_id'] ?? ''),
                'merchant_request_id' => (string)($p['merchant_request_id'] ?? ''),
                'payment_reference' => (string)($p['payment_reference'] ?? ''),
                'mpesa_receipt_number' => (string)($p['mpesa_receipt_number'] ?? ''),
                'payment_result_desc' => (string)($p['payment_result_desc'] ?? ''),
                'payment_requested_at' => mongoDateToString($p['payment_requested_at'] ?? null),
                'paid_at' => mongoDateToString($p['paid_at'] ?? null),
                'images' => apiNormalizePropertyImages($p['images'] ?? []),
                'rooms' => apiValueToArray($p['rooms'] ?? []),
                'created_at' => mongoDateToString($p['created_at'] ?? null),
            ];
        }

        $response['success'] = true;
        $response['hidden'] = false;
        $response['data'] = $properties;
        echo json_encode($response);
        exit;
    }

    $conn = apiMysql();
    apiEnsurePropertiesSchema($conn);
    $marketerId = apiResolveMySqlMarketerId($conn);
    if ($marketerId <= 0) {
        $response['message'] = 'Authentication required';
        echo json_encode($response);
        exit;
    }

    $orderColumn = apiMysqlOrderColumn($conn, 'properties');
    $stmt = $conn->prepare('
        SELECT *
        FROM properties
        WHERE marketer_id = ?
        ORDER BY ' . $orderColumn . ' DESC, id DESC
    ');
    $stmt->execute([$marketerId]);

    $response['success'] = true;
    $response['hidden'] = false;
    $response['data'] = array_map(
        static fn(array $row): array => apiBuildMySqlPropertyRow($conn, $row),
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );
} catch (Throwable $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log('Get Properties Error: ' . $e->getMessage());
}

echo json_encode($response);
