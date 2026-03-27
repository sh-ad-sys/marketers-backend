<?php
/**
 * Request password reset (MongoDB)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/password-reset-common.php';

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit;
}

try {
    $db = mongoDb();
    ensurePasswordResetTable($db);

    $data = json_decode(file_get_contents('php://input'), true);
    $type = trim($data['type'] ?? '');
    $email = trim($data['email'] ?? '');

    if (!in_array($type, ['admin', 'marketer'], true)) {
        $response['message'] = 'Invalid account type';
        echo json_encode($response);
        exit;
    }

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = 'Valid email is required';
        echo json_encode($response);
        exit;
    }

    $collection = $type === 'admin' ? $db->admins : $db->marketers;
    $user = $collection->findOne(['email' => $email]);

    if (!$user) {
        $response['success'] = false;
        $response['message'] = 'Email does not match any ' . $type . ' account.';
        echo json_encode($response);
        exit;
    }

    $userId = normalizeId($user['_id'] ?? 0);
    $token = randomToken(64);
    $tokenHash = password_hash($token, PASSWORD_DEFAULT);
    $expiresAt = new MongoDB\BSON\UTCDateTime((time() + 1800) * 1000);

    $db->password_resets->updateMany(
        ['user_type' => $type, 'user_id' => $userId, 'used_at' => null],
        ['$set' => ['used_at' => mongoNow()]]
    );

    $resetId = nextResetId($db->password_resets);
    $db->password_resets->insertOne([
        '_id' => $resetId,
        'user_type' => $type,
        'user_id' => $userId,
        'email' => $email,
        'token_hash' => $tokenHash,
        'expires_at' => $expiresAt,
        'used_at' => null,
        'created_at' => mongoNow(),
    ]);

    $adminResetBase = rtrim(loadEnvValue('RESET_ADMIN_URL', 'http://localhost:3001/reset-password'), '/');
    $userResetBase = rtrim(loadEnvValue('RESET_USER_URL', 'http://localhost:3000/reset-password'), '/');
    $base = $type === 'admin' ? $adminResetBase : $userResetBase;

    $resetLink = $base . '?token=' . urlencode($token) . '&email=' . urlencode($email) . '&type=' . urlencode($type);

    $displayName = (string)($user['name'] ?? $user['full_name'] ?? 'User');
    $subject = 'PlotConnect Password Reset';
    $html = '<p>Hello ' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . ',</p>'
      . '<p>You requested a password reset. Click the link below to set a new password:</p>'
      . '<p><a href="' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '">Reset Password</a></p>'
      . '<p>This link expires in 30 minutes.</p>'
      . '<p>If you did not request this, you can ignore this email.</p>';
    $text = "Hello {$displayName},\n\nReset your password using this link:\n{$resetLink}\n\nThis link expires in 30 minutes.";

    smtpSendMail($email, $subject, $html, $text);

    $response['success'] = true;
    $response['message'] = 'Reset link sent successfully.';
} catch (Throwable $e) {
    error_log('Request reset error: ' . $e->getMessage());
    $response['success'] = false;
    $response['message'] = 'Unable to send reset link right now. Please try again later.';
}

echo json_encode($response);
