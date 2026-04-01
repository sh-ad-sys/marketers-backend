<?php
/**
 * Login API (MongoDB)
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
ensureSession();

function marketerLockMessage(array $marketer): string
{
    return (string)($marketer['lock_reason'] ?? '') === 'failed_attempts'
        ? 'Your account is locked after too many failed login attempts. Please contact admin.'
        : 'Your account has been blocked. Please contact admin.';
}

function resetMarketerLoginAttempts(int $marketerId): void
{
    if ($marketerId <= 0) {
        return;
    }

    mongoDb()->marketers->updateOne(
        ['_id' => $marketerId],
        ['$set' => [
            'failed_login_attempts' => 0,
            'updated_at' => mongoNow(),
            'lock_reason' => null,
            'locked_at' => null,
        ]]
    );
}

function recordMarketerFailedLogin(array $marketer): array
{
    $collection = mongoDb()->marketers;
    $marketerId = normalizeId($marketer['_id'] ?? $marketer['id'] ?? 0);
    $nextAttempts = (int)($marketer['failed_login_attempts'] ?? 0) + 1;
    $shouldLock = loginAttemptShouldLock($nextAttempts);
    $update = [
        'failed_login_attempts' => $nextAttempts,
        'updated_at' => mongoNow(),
    ];

    if ($shouldLock) {
        $update['is_blocked'] = 1;
        $update['lock_reason'] = 'failed_attempts';
        $update['locked_at'] = mongoNow();
    }

    $collection->updateOne(['_id' => $marketerId], ['$set' => $update]);
    $marketer['failed_login_attempts'] = $nextAttempts;
    if ($shouldLock) {
        $marketer['is_blocked'] = 1;
        $marketer['lock_reason'] = 'failed_attempts';
    }

    return $marketer;
}

try {
    $db = mongoDb();

    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        $response['message'] = 'Invalid request data';
        echo json_encode($response);
        exit;
    }

    $type = trim((string)($data['type'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $portal = currentAdminPortal(is_array($data) ? $data : []);

    if ($type === 'admin') {
        $identifier = trim((string)($data['email'] ?? ($data['username'] ?? '')));

        if ($identifier === '' || $password === '') {
            $response['message'] = 'Email or username and password are required';
            echo json_encode($response);
            exit;
        }

        $admin = findAdminByIdentifier($identifier, $portal);

        if (!$admin && verifyConfiguredAdminPassword($identifier, $password, $portal)) {
            $admin = ensureConfiguredAdminAccount($portal);
        }

        if (!$admin) {
            $response['message'] = $portal === 'ledger' ? 'Invalid ledger credentials' : 'Invalid admin credentials';
            echo json_encode($response);
            exit;
        }

        $adminId = normalizeId($admin['_id'] ?? $admin['id'] ?? 0);
        $isLocked = isset($admin['is_locked']) ? (int)$admin['is_locked'] === 1 : false;

        if ($isLocked) {
            $response['message'] = adminPortalErrorMessage($portal);
            echo json_encode($response);
            exit;
        }

        $hasValidPassword = password_verify($password, (string)($admin['password'] ?? ''));

        if (!$hasValidPassword && verifyConfiguredAdminPassword($identifier, $password, $portal)) {
            $admin = ensureConfiguredAdminAccount($portal);
            $adminId = normalizeId($admin['_id'] ?? $admin['id'] ?? 0);
            $hasValidPassword = $admin && password_verify($password, (string)($admin['password'] ?? ''));
        }

        if (!$hasValidPassword) {
            if ($portal === 'admin') {
                $admin = recordAdminFailedLogin($admin, true);
                if ((int)($admin['is_locked'] ?? 0) === 1) {
                    $response['message'] = 'Admin account locked after too many failed login attempts. Ledger must unlock it.';
                    echo json_encode($response);
                    exit;
                }
            }

            $response['message'] = $portal === 'ledger' ? 'Invalid ledger credentials' : 'Invalid admin credentials';
            echo json_encode($response);
            exit;
        }

        resetAdminLoginAttempts($adminId);

        $token = bin2hex(random_bytes(32));
        $_SESSION['user_id'] = $adminId;
        $_SESSION['username'] = (string)($admin['username'] ?? $identifier);
        $_SESSION['full_name'] = (string)($admin['full_name'] ?? ($_SESSION['username'] ?? 'Admin'));
        $_SESSION['email'] = (string)($admin['email'] ?? '');
        $_SESSION['user_type'] = 'admin';
        $_SESSION['admin_portal'] = $portal;
        $_SESSION['token'] = $token;

        $response['success'] = true;
        $response['message'] = 'Login successful';
        $response['data'] = [
            'id' => $adminId,
            'username' => (string)($admin['username'] ?? ''),
            'full_name' => (string)($admin['full_name'] ?? ''),
            'email' => (string)($admin['email'] ?? ''),
            'user_type' => 'admin',
            'portal' => $portal,
            'token' => $token,
            'is_locked' => 0,
            'failed_login_attempts' => 0,
        ];

        echo json_encode($response);
        exit;
    }

    if (($email === '' && $phone === '') || $password === '') {
        $response['message'] = 'Email or phone number and password are required';
        echo json_encode($response);
        exit;
    }

    if ($email !== '') {
        $marketer = $db->marketers->findOne([
            '$or' => [
                ['email' => $email],
                ['name' => $email],
            ],
        ]);
    } else {
        $marketer = $db->marketers->findOne(['phone' => $phone]);
    }

    if (!$marketer) {
        $response['message'] = 'Invalid marketer credentials';
        echo json_encode($response);
        exit;
    }

    $marketer = adminDocumentToArray($marketer) ?: [];

    $isActive = isset($marketer['is_active']) ? (int)$marketer['is_active'] : 1;
    if ($isActive !== 1) {
        $response['message'] = 'Your account is inactive.';
        echo json_encode($response);
        exit;
    }

    if (isset($marketer['is_blocked']) && (int)$marketer['is_blocked'] === 1) {
        $response['message'] = marketerLockMessage($marketer);
        echo json_encode($response);
        exit;
    }

    if (!password_verify($password, (string)($marketer['password'] ?? ''))) {
        $marketer = recordMarketerFailedLogin($marketer);
        if ((int)($marketer['is_blocked'] ?? 0) === 1) {
            $response['message'] = 'Your account is locked after too many failed login attempts. Please contact admin.';
            echo json_encode($response);
            exit;
        }

        $response['message'] = 'Invalid marketer credentials';
        echo json_encode($response);
        exit;
    }

    $token = bin2hex(random_bytes(32));
    $marketerId = normalizeId($marketer['_id'] ?? $marketer['id'] ?? 0);
    resetMarketerLoginAttempts($marketerId);

    $_SESSION['user_id'] = $marketerId;
    $_SESSION['username'] = (string)($marketer['name'] ?? '');
    $_SESSION['full_name'] = (string)($marketer['name'] ?? '');
    $_SESSION['user_type'] = 'marketer';
    $_SESSION['marketer_id'] = $marketerId;
    $_SESSION['phone'] = (string)($marketer['phone'] ?? '');
    $_SESSION['email'] = (string)($marketer['email'] ?? '');
    $_SESSION['token'] = $token;

    $response['success'] = true;
    $response['message'] = 'Login successful';
    $response['data'] = [
        'id' => $marketerId,
        'marketer_id' => $marketerId,
        'name' => (string)($marketer['name'] ?? ''),
        'email' => (string)($marketer['email'] ?? ''),
        'phone' => (string)($marketer['phone'] ?? ''),
        'user_type' => 'marketer',
        'token' => $token,
        'is_authorized' => isset($marketer['is_authorized']) ? (int)$marketer['is_authorized'] : 0,
        'is_blocked' => isset($marketer['is_blocked']) ? (int)$marketer['is_blocked'] : 0,
        'must_change_password' => isset($marketer['must_change_password']) ? (int)$marketer['must_change_password'] : 0,
        'failed_login_attempts' => 0,
    ];
} catch (Throwable $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log('Login Error: ' . $e->getMessage());
}

echo json_encode($response);
