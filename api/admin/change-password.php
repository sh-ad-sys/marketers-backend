<?php
/**
 * Ledger admin change password API.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id, X-Auth-Portal');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../shared/admin-auth.php';

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

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $portal = currentAdminPortal($data);
    $currentPassword = (string)($data['current_password'] ?? '');
    $newPassword = (string)($data['new_password'] ?? '');
    $identifier = trim((string)($data['email'] ?? currentAdminIdentifier()));

    if ($portal !== 'ledger') {
        $response['message'] = 'Only ledger can change this password here';
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

    $admin = findAdminByIdentifier($identifier, 'ledger');
    if (!$admin && verifyConfiguredAdminPassword($identifier, $currentPassword, 'ledger')) {
        $admin = ensureConfiguredAdminAccount('ledger');
    }

    if (!$admin) {
        $response['message'] = 'Ledger account not found';
        echo json_encode($response);
        exit;
    }

    $hasValidPassword = password_verify($currentPassword, (string)($admin['password'] ?? ''));
    if (!$hasValidPassword && verifyConfiguredAdminPassword($identifier, $currentPassword, 'ledger')) {
        $admin = ensureConfiguredAdminAccount('ledger');
        $hasValidPassword = $admin && password_verify($currentPassword, (string)($admin['password'] ?? ''));
    }

    if (!$hasValidPassword) {
        $response['message'] = 'Current password is incorrect';
        echo json_encode($response);
        exit;
    }

    $adminId = normalizeId($admin['_id'] ?? $admin['id'] ?? 0);
    adminCollection()->updateOne(
        ['_id' => $adminId],
        ['$set' => [
            'password' => password_hash($newPassword, PASSWORD_DEFAULT),
            'failed_login_attempts' => 0,
            'is_locked' => 0,
            'lock_reason' => null,
            'locked_at' => null,
            'updated_at' => mongoNow(),
        ]]
    );

    $response['success'] = true;
    $response['message'] = 'Ledger password changed successfully';
} catch (Throwable $e) {
    error_log('Ledger change password error: ' . $e->getMessage());
    $response['message'] = 'Password change failed: ' . $e->getMessage();
}

echo json_encode($response);
