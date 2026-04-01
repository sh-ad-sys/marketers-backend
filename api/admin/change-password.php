<?php
/**
 * Ledger admin change password API.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../shared/admin-portals.php';

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit;
}

try {
    if (!isAdminLoggedIn()) {
        $response['message'] = 'Admin access required';
        echo json_encode($response);
        exit;
    }

    $data = apiJsonInput();
    $portal = apiResolveAdminPortalFromRequest($data);
    $currentPassword = (string)($data['current_password'] ?? '');
    $newPassword = (string)($data['new_password'] ?? '');
    $identifier = trim((string)($data['email'] ?? apiCurrentAdminIdentifier()));

    if ($portal !== 'ledger') {
        $response['message'] = 'Only the ledger portal password can be changed here';
        echo json_encode($response);
        exit;
    }

    if ($currentPassword === '' || $newPassword === '') {
        $response['message'] = 'Current password and new password are required';
        echo json_encode($response);
        exit;
    }

    if (strlen($newPassword) < 6) {
        $response['message'] = 'New password must be at least 6 characters';
        echo json_encode($response);
        exit;
    }

    if ($identifier === '') {
        $response['message'] = 'Unable to determine the ledger account';
        echo json_encode($response);
        exit;
    }

    if (!apiVerifyAdminPortalPassword($identifier, $currentPassword, 'ledger')) {
        $response['message'] = 'Current password is incorrect';
        echo json_encode($response);
        exit;
    }

    apiPersistAdminPortalPasswordHash('ledger', password_hash($newPassword, PASSWORD_DEFAULT));

    $response['success'] = true;
    $response['message'] = 'Ledger password changed successfully';
} catch (Throwable $e) {
    error_log('Ledger change password error: ' . $e->getMessage());
    $response['message'] = 'Password change failed: ' . $e->getMessage();
}

echo json_encode($response);
