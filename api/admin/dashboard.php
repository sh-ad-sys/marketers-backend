<?php
/**
 * Admin Dashboard API
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
        $response['success'] = true;
        $response['data'] = [
            'total_marketers' => (int)$db->marketers->countDocuments(),
            'total_properties' => (int)$db->properties->countDocuments(),
            'pending_properties' => (int)$db->properties->countDocuments(['status' => 'pending']),
            'approved_properties' => (int)$db->properties->countDocuments(['status' => 'approved']),
            'rejected_properties' => (int)$db->properties->countDocuments(['status' => 'rejected']),
        ];

        echo json_encode($response);
        exit;
    }

    $conn = apiMysql();
    $response['success'] = true;
    $response['data'] = [
        'total_marketers' => (int)$conn->query('SELECT COUNT(*) FROM marketers')->fetchColumn(),
        'total_properties' => (int)$conn->query('SELECT COUNT(*) FROM properties')->fetchColumn(),
        'pending_properties' => (int)$conn->query("SELECT COUNT(*) FROM properties WHERE status = 'pending'")->fetchColumn(),
        'approved_properties' => (int)$conn->query("SELECT COUNT(*) FROM properties WHERE status = 'approved'")->fetchColumn(),
        'rejected_properties' => (int)$conn->query("SELECT COUNT(*) FROM properties WHERE status = 'rejected'")->fetchColumn(),
    ];
} catch (Throwable $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log('Admin Dashboard Error: ' . $e->getMessage());
}

echo json_encode($response);
