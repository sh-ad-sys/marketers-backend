<?php
/**
 * Admin Properties API
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../shared/storage.php';

$response = ['success' => false, 'message' => '', 'data' => []];

try {
    if (!isAdminLoggedIn()) {
        $response['message'] = 'Admin access required';
        echo json_encode($response);
        exit;
    }

    if (apiUsesMongoStorage()) {
        require_once __DIR__ . '/../mongo/config.php';

        $db = mongoDb();

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $marketerMap = [];
            foreach ($db->marketers->find([], ['projection' => ['_id' => 1, 'name' => 1, 'phone' => 1]]) as $m) {
                $marketerMap[normalizeId($m['_id'])] = [
                    'name' => (string)($m['name'] ?? ''),
                    'phone' => (string)($m['phone'] ?? ''),
                ];
            }

            $properties = [];
            foreach ($db->properties->find([], ['sort' => ['created_at' => -1, '_id' => -1]]) as $p) {
                $marketerId = normalizeId($p['marketer_id'] ?? 0);
                $mk = $marketerMap[$marketerId] ?? ['name' => 'Unknown', 'phone' => ''];
                $properties[] = [
                    'id' => normalizeId($p['_id'] ?? 0),
                    'marketer_id' => $marketerId,
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
                    'marketer_name' => $mk['name'],
                    'marketer_phone' => $mk['phone'],
                ];
            }

            $response['success'] = true;
            $response['data'] = $properties;
            echo json_encode($response);
            exit;
        }

        $data = apiJsonInput() ?: $_REQUEST;
        $action = trim((string)($data['action'] ?? ''));

        if ($action === 'delete') {
            $db->properties->deleteOne(['_id' => (int)($data['id'] ?? 0)]);
            echo json_encode(['success' => true, 'message' => 'Property deleted successfully']);
            exit;
        }

        if ($action === 'update_status') {
            $db->properties->updateOne(
                ['_id' => (int)($data['id'] ?? 0)],
                ['$set' => ['status' => trim((string)($data['status'] ?? 'pending'))]]
            );
            echo json_encode(['success' => true, 'message' => 'Property status updated successfully']);
            exit;
        }

        if ($action === 'refresh_user_plots') {
            echo json_encode(['success' => true, 'message' => 'Refresh completed successfully']);
            exit;
        }

        $response['message'] = 'Invalid action';
        echo json_encode($response);
        exit;
    }

    $conn = apiMysql();
    apiEnsurePropertiesSchema($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $orderColumn = apiMysqlOrderColumn($conn, 'properties');
        $stmt = $conn->query('
            SELECT p.*, m.name AS marketer_name, m.phone AS marketer_phone
            FROM properties p
            LEFT JOIN marketers m ON p.marketer_id = m.id
            ORDER BY p.' . $orderColumn . ' DESC, p.id DESC
        ');
        $properties = array_map(
            static fn(array $row): array => apiBuildMySqlPropertyRow($conn, $row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );

        $response['success'] = true;
        $response['data'] = $properties;
        echo json_encode($response);
        exit;
    }

    $data = apiJsonInput() ?: $_REQUEST;
    $action = trim((string)($data['action'] ?? ''));

    if ($action === 'delete') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            $response['message'] = 'Invalid property ID';
            echo json_encode($response);
            exit;
        }

        $stmt = $conn->prepare('DELETE FROM property_rooms WHERE property_id = ?');
        $stmt->execute([$id]);
        $stmt = $conn->prepare('DELETE FROM properties WHERE id = ?');
        $stmt->execute([$id]);

        echo json_encode(['success' => true, 'message' => 'Property deleted successfully']);
        exit;
    }

    if ($action === 'update_status') {
        $id = (int)($data['id'] ?? 0);
        $status = trim((string)($data['status'] ?? ''));
        if ($id <= 0 || !in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $response['message'] = 'Invalid property ID or status';
            echo json_encode($response);
            exit;
        }

        $stmt = $conn->prepare('UPDATE properties SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
        echo json_encode(['success' => true, 'message' => 'Property status updated successfully']);
        exit;
    }

    if ($action === 'refresh_user_plots') {
        echo json_encode(['success' => true, 'message' => 'Refresh completed successfully']);
        exit;
    }

    $response['message'] = 'Invalid action';
} catch (Throwable $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log('Properties API Error: ' . $e->getMessage());
}

echo json_encode($response);
