<?php
/**
 * Delete marketer property API (MongoDB)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../mongo/config.php';
ensureSession();

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit;
}

try {
    $db = mongoDb();
    $data = json_decode(file_get_contents('php://input'), true);
    $propertyId = (int)($data['id'] ?? 0);

    $marketerId = isset($_SERVER['HTTP_X_AUTH_MARKETER_ID']) ? (int)$_SERVER['HTTP_X_AUTH_MARKETER_ID'] : 0;
    if ($marketerId <= 0 && isset($_SESSION['marketer_id'])) {
        $marketerId = normalizeId($_SESSION['marketer_id']);
    }

    if ($propertyId <= 0 || $marketerId <= 0) {
        $response['message'] = 'Invalid property or user';
        echo json_encode($response);
        exit;
    }

    $result = $db->properties->deleteOne(['_id' => $propertyId, 'marketer_id' => $marketerId]);
    if ($result->getDeletedCount() === 0) {
        $response['message'] = 'Property not found or not allowed';
        echo json_encode($response);
        exit;
    }

    $response['success'] = true;
    $response['message'] = 'Property deleted successfully';
} catch (Throwable $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($response);
