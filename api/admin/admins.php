<?php
/**
 * Admin management API (MongoDB)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id, X-Auth-Portal');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../shared/admin-auth.php';

$response = ['success' => false, 'message' => '', 'data' => []];

function adminApiGeneratePassword(int $length = 12): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $password = '';
    $max = strlen($chars) - 1;

    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }

    return $password;
}

function adminApiUsernameFromEmail(string $email): string
{
    $base = strtolower(trim((string)preg_replace('/@.*$/', '', $email)));
    $base = preg_replace('/[^a-z0-9._-]+/', '', $base);
    return $base !== '' ? $base : 'admin';
}

function adminApiFullNameFromEmail(string $email): string
{
    $base = preg_replace('/@.*$/', '', trim($email));
    $base = str_replace(['.', '_', '-'], ' ', $base);
    $base = trim(preg_replace('/\s+/', ' ', $base));

    return $base !== '' ? ucwords($base) : 'Administrator';
}

function adminApiMongoRow($doc): array
{
    $admin = adminDocumentToArray($doc) ?: [];

    return [
        'id' => normalizeId($admin['_id'] ?? $admin['id'] ?? 0),
        'username' => (string)($admin['username'] ?? ''),
        'full_name' => (string)($admin['full_name'] ?? ''),
        'email' => (string)($admin['email'] ?? ''),
        'portal' => adminDocPortal($admin),
        'created_at' => mongoDateToString($admin['created_at'] ?? null),
        'is_locked' => isset($admin['is_locked']) ? (int)$admin['is_locked'] : 0,
        'failed_login_attempts' => isset($admin['failed_login_attempts']) ? (int)$admin['failed_login_attempts'] : 0,
        'lock_reason' => (string)($admin['lock_reason'] ?? ''),
    ];
}

try {
    if (!isAdminLoggedIn()) {
        $response['message'] = 'Admin access required';
        echo json_encode($response);
        exit;
    }

    ensureConfiguredAdminAccount('ledger');
    if ((string)(adminPortalProfile('admin')['password'] ?? '') !== '') {
        ensureConfiguredAdminAccount('admin');
    }

    $collection = adminCollection();

    if ($_SERVER['REQUEST_METHOD'] === 'GET' || (isset($_REQUEST['action']) && $_REQUEST['action'] === 'list')) {
        $cursor = $collection->find([], ['sort' => ['created_at' => -1, '_id' => -1]]);
        $admins = [];
        foreach ($cursor as $doc) {
            $admins[] = adminApiMongoRow($doc);
        }

        $response['success'] = true;
        $response['data'] = $admins;
        echo json_encode($response);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true) ?: $_REQUEST;
    $action = trim((string)($data['action'] ?? ''));
    $portal = currentAdminPortal(is_array($data) ? $data : []);

    if ($action === 'unlock') {
        if ($portal !== 'ledger') {
            $response['message'] = 'Only ledger can unlock admin accounts';
            echo json_encode($response);
            exit;
        }

        $id = isset($data['id']) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $response['message'] = 'Invalid admin ID';
            echo json_encode($response);
            exit;
        }

        $admin = findAdminById($id);
        if (!$admin || adminDocPortal($admin) !== 'admin') {
            $response['message'] = 'Admin account not found';
            echo json_encode($response);
            exit;
        }

        $collection->updateOne(
            ['_id' => $id],
            ['$set' => [
                'is_locked' => 0,
                'failed_login_attempts' => 0,
                'lock_reason' => null,
                'locked_at' => null,
                'updated_at' => mongoNow(),
            ]]
        );

        $response['success'] = true;
        $response['message'] = 'Admin unlocked successfully';
        echo json_encode($response);
        exit;
    }

    $email = strtolower(trim((string)($data['email'] ?? '')));
    $username = trim((string)($data['username'] ?? ''));
    $fullName = trim((string)($data['full_name'] ?? ($data['name'] ?? '')));
    $password = trim((string)($data['password'] ?? ''));
    $generatedPassword = '';

    if ($email === '') {
        $response['message'] = 'Admin email is required';
        echo json_encode($response);
        exit;
    }

    if ($username === '') {
        $username = adminApiUsernameFromEmail($email);
    }

    if ($fullName === '') {
        $fullName = adminApiFullNameFromEmail($email);
    }

    if ($password === '') {
        $generatedPassword = adminApiGeneratePassword();
        $password = $generatedPassword;
    }

    $existingAdmin = $collection->findOne([
        '$or' => [
            ['email' => $email],
            ['username' => $username],
        ],
    ]);

    if ($existingAdmin) {
        $response['message'] = 'An admin with that email or username already exists';
        echo json_encode($response);
        exit;
    }

    $newId = adminNextId($collection);
    $timestamp = mongoNow();

    $collection->insertOne([
        '_id' => $newId,
        'portal' => 'admin',
        'username' => $username,
        'full_name' => $fullName,
        'email' => $email,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'failed_login_attempts' => 0,
        'is_locked' => 0,
        'lock_reason' => null,
        'locked_at' => null,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);

    $createdAdmin = findAdminById($newId);

    $response['success'] = true;
    $response['message'] = 'Admin created successfully';
    $response['data'] = adminApiMongoRow($createdAdmin ?: ['_id' => $newId, 'username' => $username, 'full_name' => $fullName, 'email' => $email, 'created_at' => $timestamp, 'portal' => 'admin']);
    if ($generatedPassword !== '') {
        $response['data']['generated_password'] = $generatedPassword;
    }
} catch (Throwable $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log('Admins API Error: ' . $e->getMessage());
}

echo json_encode($response);
