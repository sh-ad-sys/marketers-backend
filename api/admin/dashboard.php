<?php
/**
 * Admin Dashboard API (MongoDB)
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

    $totalMarketers = $db->marketers->countDocuments();
    $totalProperties = $db->properties->countDocuments();
    $pendingProperties = $db->properties->countDocuments(['status' => 'pending']);
    $approvedProperties = $db->properties->countDocuments(['status' => 'approved']);
    $rejectedProperties = $db->properties->countDocuments(['status' => 'rejected']);

    $response['success'] = true;
    $response['data'] = [
        'total_marketers' => (int)$totalMarketers,
        'total_properties' => (int)$totalProperties,
        'pending_properties' => (int)$pendingProperties,
        'approved_properties' => (int)$approvedProperties,
        'rejected_properties' => (int)$rejectedProperties,
    ];
} catch (Throwable $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log('Admin Dashboard Error: ' . $e->getMessage());
}

echo json_encode($response);
