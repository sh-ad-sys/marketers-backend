<?php
/**
 * Admin Properties API (MongoDB)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../mongo/config.php';

$response = ['success' => false, 'message' => '', 'data' => []];

try {
    $role = $_SERVER['HTTP_X_AUTH_ROLE'] ?? '';
    if ($role !== 'admin') {
        $response['message'] = 'Admin access required';
        echo json_encode($response);
        exit;
    }

    $db = mongoDb();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $marketerMap = [];
        foreach ($db->marketers->find([], ['projection' => ['_id' => 1, 'name' => 1, 'phone' => 1]]) as $m) {
            $marketerMap[normalizeId($m['_id'])] = [
                'name' => (string)($m['name'] ?? ''),
                'phone' => (string)($m['phone'] ?? ''),
            ];
        }

        $cursor = $db->properties->find([], ['sort' => ['created_at' => -1, '_id' => -1]]);
        $properties = [];
        foreach ($cursor as $p) {
            $marketerId = normalizeId($p['marketer_id'] ?? 0);
            $mk = $marketerMap[$marketerId] ?? ['name' => 'Unknown', 'phone' => ''];
            $properties[] = [
                'id' => normalizeId($p['_id'] ?? 0),
                'marketer_id' => $marketerId,
                'owner_name' => (string)($p['owner_name'] ?? ''),
                'owner_email' => (string)($p['owner_email'] ?? ''),
                'phone' => (string)($p['phone'] ?? ''),
                'property_name' => (string)($p['property_name'] ?? ''),
                'property_location' => (string)($p['property_location'] ?? ''),
                'property_type' => (string)($p['property_type'] ?? ''),
                'booking_type' => (string)($p['booking_type'] ?? ''),
                'package_selected' => (string)($p['package_selected'] ?? ''),
                'county' => (string)($p['county'] ?? ''),
                'area' => (string)($p['area'] ?? ''),
                'status' => (string)($p['status'] ?? 'pending'),
                'rooms' => $p['rooms'] ?? [],
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

    $data = json_decode(file_get_contents('php://input'), true) ?? $_REQUEST;
    $action = trim($data['action'] ?? '');

    if ($action === 'delete') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            $response['message'] = 'Invalid property ID';
            echo json_encode($response);
            exit;
        }

        $db->properties->deleteOne(['_id' => $id]);
        $response['success'] = true;
        $response['message'] = 'Property deleted successfully';
        echo json_encode($response);
        exit;
    }

    if ($action === 'update_status') {
        $id = (int)($data['id'] ?? 0);
        $status = trim($data['status'] ?? '');
        if ($id <= 0 || !in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $response['message'] = 'Invalid property ID or status';
            echo json_encode($response);
            exit;
        }

        $db->properties->updateOne(['_id' => $id], ['$set' => ['status' => $status]]);
        $response['success'] = true;
        $response['message'] = 'Property status updated successfully';
        echo json_encode($response);
        exit;
    }

    if ($action === 'refresh_user_plots') {
        $db->app_settings->updateOne(
            ['_id' => 'marketer_properties_hidden'],
            ['$set' => ['value' => 1, 'updated_at' => mongoNow()]],
            ['upsert' => true]
        );
        $response['success'] = true;
        $response['message'] = 'User properties refreshed';
        echo json_encode($response);
        exit;
    }

    $response['message'] = 'Invalid action';
} catch (Throwable $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log('Properties API Error: ' . $e->getMessage());
}

echo json_encode($response);
