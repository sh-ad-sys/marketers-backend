<?php
/**
 * My Properties API (MongoDB)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../mongo/config.php';
ensureSession();

$response = ['success' => false, 'message' => '', 'data' => []];

try {
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

    $settings = $db->app_settings->findOne(['_id' => 'marketer_properties_hidden']);
    $hidden = $settings && isset($settings['value']) && (int)$settings['value'] === 1;

    $properties = [];
    if (!$hidden) {
        $cursor = $db->properties->find(['marketer_id' => $marketerId], ['sort' => ['created_at' => -1, '_id' => -1]]);
        foreach ($cursor as $p) {
            $properties[] = [
                'id' => normalizeId($p['_id'] ?? 0),
                'marketer_id' => normalizeId($p['marketer_id'] ?? 0),
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
            ];
        }
    }

    $response['success'] = true;
    $response['hidden'] = $hidden;
    $response['data'] = $properties;
} catch (Throwable $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log('Get Properties Error: ' . $e->getMessage());
}

echo json_encode($response);
