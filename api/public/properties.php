<?php
/**
 * PlotConnect - Public Properties API
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../shared/storage.php';

try {
    if (apiUsesMongoStorage()) {
        require_once __DIR__ . '/../mongo/config.php';

        $db = mongoDb();
        $marketerMap = [];
        foreach ($db->marketers->find([], ['projection' => ['_id' => 1, 'name' => 1, 'phone' => 1]]) as $m) {
            $marketerMap[normalizeId($m['_id'] ?? 0)] = [
                'name' => (string)($m['name'] ?? ''),
                'phone' => (string)($m['phone'] ?? ''),
            ];
        }

        $properties = [];
        foreach ($db->properties->find(['status' => 'approved'], ['sort' => ['created_at' => -1, '_id' => -1]]) as $p) {
            $marketerId = normalizeId($p['marketer_id'] ?? 0);
            $mk = $marketerMap[$marketerId] ?? ['name' => 'Unknown', 'phone' => ''];
            $primaryPhone = (string)($p['phone_number_1'] ?? ($p['phone'] ?? ($p['phone_number'] ?? '')));

            $properties[] = [
                'id' => normalizeId($p['_id'] ?? 0),
                'marketer_id' => $marketerId,
                'owner_name' => (string)($p['owner_name'] ?? ''),
                'owner_email' => (string)($p['owner_email'] ?? ''),
                'phone' => $primaryPhone,
                'phone_number' => $primaryPhone,
                'phone_number_1' => $primaryPhone,
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
                'rooms' => $p['rooms'] ?? [],
                'created_at' => mongoDateToString($p['created_at'] ?? null),
                'marketer_name' => $mk['name'],
                'marketer_phone' => $mk['phone'],
            ];
        }

        echo json_encode(['success' => true, 'message' => 'Properties retrieved', 'data' => $properties]);
        exit;
    }

    $conn = apiMysql();
    $orderColumn = apiMysqlOrderColumn($conn, 'properties');
    $stmt = $conn->query('
        SELECT p.*, m.name AS marketer_name, m.phone AS marketer_phone
        FROM properties p
        JOIN marketers m ON p.marketer_id = m.id
        WHERE p.status = \'approved\'
        ORDER BY p.' . $orderColumn . ' DESC, p.id DESC
    ');

    $properties = array_map(
        static fn(array $row): array => apiBuildMySqlPropertyRow($conn, $row),
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );

    echo json_encode(['success' => true, 'message' => 'Properties retrieved', 'data' => $properties]);
} catch (Throwable $e) {
    error_log('DB Connection Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
}
