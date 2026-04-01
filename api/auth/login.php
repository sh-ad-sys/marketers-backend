<?php
/**
 * Login API
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/admin-portals.php';

$response = ['success' => false, 'message' => ''];
apiEnsureSessionStarted();

function loginAdminMongoFilter(string $identifier): array
{
    return [
        '$or' => [
            ['username' => $identifier],
            ['email' => $identifier],
        ],
    ];
}

function loginMongoDocumentToArray($document): ?array
{
    if ($document === null) {
        return null;
    }

    if (is_array($document)) {
        return $document;
    }

    if (is_object($document) && method_exists($document, 'getArrayCopy')) {
        return $document->getArrayCopy();
    }

    return (array)$document;
}

function loginRequestedAdminPortal(array $data): string
{
    return function_exists('normalizeAdminPortal')
        ? normalizeAdminPortal((string)($data['portal'] ?? 'admin'))
        : 'admin';
}

function mongoUpsertConfiguredAdmin($adminsCollection, string $identifier, string $portal = 'admin'): ?array
{
    $normalizedPortal = function_exists('normalizeAdminPortal')
        ? normalizeAdminPortal($portal)
        : 'admin';

    if ($normalizedPortal !== 'admin') {
        return null;
    }

    if (!function_exists('adminIdentifierMatches') || !adminIdentifierMatches($identifier, $normalizedPortal)) {
        return null;
    }

    $profile = function_exists('adminDefaultProfile')
        ? adminDefaultProfile($normalizedPortal)
        : [
            'username' => 'techswift-admin',
            'email' => '',
            'full_name' => 'Techswifttrix Admin',
        ];

    $passwordHash = function_exists('adminPasswordHash')
        ? adminPasswordHash($normalizedPortal)
        : (defined('ADMIN_PASSWORD') ? (string)ADMIN_PASSWORD : '');
    $filters = [];

    if (!empty($profile['username'])) {
        $filters[] = ['username' => (string)$profile['username']];
    }
    if (!empty($profile['email'])) {
        $filters[] = ['email' => (string)$profile['email']];
    }

    if ($passwordHash === '' || $filters === []) {
        return null;
    }

    $existingAdmin = loginMongoDocumentToArray($adminsCollection->findOne(['$or' => $filters]));
    $adminId = normalizeId($existingAdmin['_id'] ?? $existingAdmin['id'] ?? 0);

    if ($adminId <= 0) {
        $latestAdmin = loginMongoDocumentToArray($adminsCollection->findOne([], [
            'sort' => ['_id' => -1],
            'projection' => ['_id' => 1],
        ]));
        $adminId = normalizeId($latestAdmin['_id'] ?? $latestAdmin['id'] ?? 0) + 1;
    }

    $timestamp = gmdate('c');
    $adminDoc = [
        '_id' => $adminId,
        'username' => (string)$profile['username'],
        'email' => (string)$profile['email'],
        'full_name' => (string)$profile['full_name'],
        'password' => $passwordHash,
        'updated_at' => $timestamp,
    ];

    if (!$existingAdmin) {
        $adminDoc['created_at'] = $timestamp;
    } elseif (isset($existingAdmin['created_at'])) {
        $adminDoc['created_at'] = $existingAdmin['created_at'];
    }

    $adminsCollection->updateOne(
        ['_id' => $adminId],
        ['$set' => $adminDoc],
        ['upsert' => true]
    );

    $storedAdmin = loginMongoDocumentToArray($adminsCollection->findOne(['_id' => $adminId]));
    if ($storedAdmin) {
        return $storedAdmin;
    }

    return $adminDoc;
}

function mysqlUpsertConfiguredAdmin(PDO $conn, string $identifier, string $portal = 'admin'): ?array
{
    $normalizedPortal = function_exists('normalizeAdminPortal')
        ? normalizeAdminPortal($portal)
        : 'admin';

    if ($normalizedPortal !== 'admin') {
        return null;
    }

    if (!function_exists('adminIdentifierMatches') || !adminIdentifierMatches($identifier, $normalizedPortal)) {
        return null;
    }

    $profile = function_exists('adminDefaultProfile')
        ? adminDefaultProfile($normalizedPortal)
        : [
            'username' => 'techswift-admin',
            'email' => '',
            'full_name' => 'Techswifttrix Admin',
        ];
    $passwordHash = function_exists('adminPasswordHash')
        ? adminPasswordHash($normalizedPortal)
        : (defined('ADMIN_PASSWORD') ? (string)ADMIN_PASSWORD : '');

    if ($passwordHash === '') {
        return null;
    }

    $stmt = $conn->prepare('
        SELECT id, username, full_name, email, password
        FROM admins
        WHERE username = ? OR email = ?
        LIMIT 1
    ');
    $stmt->execute([
        (string)($profile['username'] ?? ''),
        (string)($profile['email'] ?? ''),
    ]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin) {
        $update = $conn->prepare('
            UPDATE admins
            SET username = ?, full_name = ?, email = ?, password = ?
            WHERE id = ?
        ');
        $update->execute([
            (string)($profile['username'] ?? ''),
            (string)($profile['full_name'] ?? 'Techswifttrix Admin'),
            (string)($profile['email'] ?? ''),
            $passwordHash,
            (int)$admin['id'],
        ]);

        $refresh = $conn->prepare('
            SELECT id, username, full_name, email, password
            FROM admins
            WHERE id = ?
            LIMIT 1
        ');
        $refresh->execute([(int)$admin['id']]);
        $storedAdmin = $refresh->fetch(PDO::FETCH_ASSOC);
        return $storedAdmin ?: $admin;
    }

    $insert = $conn->prepare('
        INSERT INTO admins (username, password, full_name, email)
        VALUES (?, ?, ?, ?)
    ');
    $insert->execute([
        (string)($profile['username'] ?? ''),
        $passwordHash,
        (string)($profile['full_name'] ?? 'Techswifttrix Admin'),
        (string)($profile['email'] ?? ''),
    ]);

    return [
        'id' => (int)$conn->lastInsertId(),
        'username' => (string)($profile['username'] ?? ''),
        'full_name' => (string)($profile['full_name'] ?? 'Techswifttrix Admin'),
        'email' => (string)($profile['email'] ?? ''),
        'password' => $passwordHash,
    ];
}

try {
    $data = apiJsonInput();
    if (!$data) {
        $response['message'] = 'Invalid request data';
        echo json_encode($response);
        exit;
    }

    $type = trim((string)($data['type'] ?? ''));

    if (apiUsesMongoStorage()) {
        require_once __DIR__ . '/../mongo/config.php';
        ensureSession();

        $db = mongoDb();
        $email = trim((string)($data['email'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $adminPortal = loginRequestedAdminPortal($data);

        if ($type === 'admin') {
            $identifier = trim((string)($data['email'] ?? ($data['username'] ?? '')));
            if ($identifier === '' || $password === '') {
                $response['message'] = 'Email or username and password are required';
                echo json_encode($response);
                exit;
            }

            if ($adminPortal === 'ledger') {
                if (!apiVerifyAdminPortalPassword($identifier, $password, 'ledger')) {
                    $response['message'] = 'Invalid ledger credentials';
                    echo json_encode($response);
                    exit;
                }

                $profile = function_exists('adminDefaultProfile')
                    ? adminDefaultProfile('ledger')
                    : [
                        'username' => 'ledger',
                        'email' => 'ledger@plotconnect.com',
                        'full_name' => 'Ledger Administrator',
                    ];
                $token = bin2hex(random_bytes(32));
                $ledgerEmail = (string)($profile['email'] ?? '');
                $ledgerUsername = (string)($profile['username'] ?? 'ledger');
                $ledgerName = (string)($profile['full_name'] ?? 'Ledger Administrator');

                $_SESSION['user_id'] = 0;
                $_SESSION['username'] = $ledgerUsername;
                $_SESSION['full_name'] = $ledgerName;
                $_SESSION['email'] = $ledgerEmail;
                $_SESSION['user_type'] = 'admin';
                $_SESSION['admin_portal'] = 'ledger';
                $_SESSION['token'] = $token;

                $response['success'] = true;
                $response['message'] = 'Login successful';
                $response['data'] = [
                    'id' => 0,
                    'username' => $ledgerUsername,
                    'full_name' => $ledgerName,
                    'email' => $ledgerEmail,
                    'user_type' => 'admin',
                    'portal' => 'ledger',
                    'token' => $token,
                ];
                echo json_encode($response);
                exit;
            }

            $admin = loginMongoDocumentToArray($db->admins->findOne(loginAdminMongoFilter($identifier)));
            $hasValidStoredPassword = $admin && password_verify($password, (string)($admin['password'] ?? ''));

            if (!$hasValidStoredPassword && function_exists('verifyAdmin') && verifyAdmin($identifier, $password, $adminPortal)) {
                $admin = mongoUpsertConfiguredAdmin($db->admins, $identifier, $adminPortal);
                $hasValidStoredPassword = $admin && password_verify($password, (string)($admin['password'] ?? ''));
            }

            if ($hasValidStoredPassword) {
                $token = bin2hex(random_bytes(32));
                $adminEmail = (string)($admin['email'] ?? '');

                $_SESSION['user_id'] = normalizeId($admin['_id'] ?? $admin['id'] ?? 0);
                $_SESSION['username'] = (string)($admin['username'] ?? '');
                $_SESSION['full_name'] = (string)($admin['full_name'] ?? $_SESSION['username']);
                $_SESSION['email'] = $adminEmail;
                $_SESSION['user_type'] = 'admin';
                $_SESSION['admin_portal'] = 'admin';
                $_SESSION['token'] = $token;

                $response['success'] = true;
                $response['message'] = 'Login successful';
                $response['data'] = [
                    'id' => normalizeId($admin['_id'] ?? $admin['id'] ?? 0),
                    'username' => (string)($admin['username'] ?? ''),
                    'full_name' => (string)($admin['full_name'] ?? $_SESSION['username']),
                    'email' => $adminEmail,
                    'user_type' => 'admin',
                    'portal' => 'admin',
                    'token' => $token,
                ];
            } else {
                $response['message'] = 'Invalid admin credentials';
            }

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

        $isActive = isset($marketer['is_active']) ? (int)$marketer['is_active'] : 1;
        if ($isActive !== 1) {
            $response['message'] = 'Your account is inactive.';
            echo json_encode($response);
            exit;
        }

        if (isset($marketer['is_blocked']) && (int)$marketer['is_blocked'] === 1) {
            $response['message'] = 'Your account has been blocked. Please contact admin.';
            echo json_encode($response);
            exit;
        }

        if (!password_verify($password, (string)($marketer['password'] ?? ''))) {
            $response['message'] = 'Invalid marketer credentials';
            echo json_encode($response);
            exit;
        }

        $token = bin2hex(random_bytes(32));
        $marketerId = normalizeId($marketer['_id'] ?? $marketer['id'] ?? 0);

        $_SESSION['user_id'] = $marketerId;
        $_SESSION['username'] = (string)($marketer['name'] ?? '');
        $_SESSION['full_name'] = (string)($marketer['name'] ?? '');
        $_SESSION['user_type'] = 'marketer';
        $_SESSION['marketer_id'] = $marketerId;
        $_SESSION['email'] = (string)($marketer['email'] ?? '');
        $_SESSION['phone'] = (string)($marketer['phone'] ?? '');
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
        ];

        echo json_encode($response);
        exit;
    }

    $conn = apiMysql();
    $password = (string)($data['password'] ?? '');
    $adminPortal = loginRequestedAdminPortal($data);

    if ($type === 'admin') {
        $identifier = trim((string)($data['email'] ?? ($data['username'] ?? '')));
        if ($identifier === '' || $password === '') {
            $response['message'] = 'Email or username and password are required';
            echo json_encode($response);
            exit;
        }

        if ($adminPortal === 'ledger') {
            if (!apiVerifyAdminPortalPassword($identifier, $password, 'ledger')) {
                $response['message'] = 'Invalid ledger credentials';
                echo json_encode($response);
                exit;
            }

            $profile = function_exists('adminDefaultProfile')
                ? adminDefaultProfile('ledger')
                : [
                    'username' => 'ledger',
                    'email' => 'ledger@plotconnect.com',
                    'full_name' => 'Ledger Administrator',
                ];
            $token = bin2hex(random_bytes(32));
            $ledgerEmail = (string)($profile['email'] ?? '');
            $ledgerUsername = (string)($profile['username'] ?? 'ledger');
            $ledgerName = (string)($profile['full_name'] ?? 'Ledger Administrator');

            $_SESSION['user_id'] = 0;
            $_SESSION['username'] = $ledgerUsername;
            $_SESSION['full_name'] = $ledgerName;
            $_SESSION['email'] = $ledgerEmail;
            $_SESSION['user_type'] = 'admin';
            $_SESSION['admin_portal'] = 'ledger';
            $_SESSION['token'] = $token;

            $response['success'] = true;
            $response['message'] = 'Login successful';
            $response['data'] = [
                'id' => 0,
                'username' => $ledgerUsername,
                'full_name' => $ledgerName,
                'email' => $ledgerEmail,
                'user_type' => 'admin',
                'portal' => 'ledger',
                'token' => $token,
            ];

            echo json_encode($response);
            exit;
        }

        $stmt = $conn->prepare('
            SELECT id, username, full_name, email, password
            FROM admins
            WHERE username = ? OR email = ?
            LIMIT 1
        ');
        $stmt->execute([$identifier, $identifier]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ((!$admin || !password_verify($password, (string)$admin['password']))
            && function_exists('verifyAdmin')
            && verifyAdmin($identifier, $password, $adminPortal)
        ) {
            $admin = mysqlUpsertConfiguredAdmin($conn, $identifier, $adminPortal);
        }

        if (!$admin || !password_verify($password, (string)$admin['password'])) {
            $response['message'] = 'Invalid admin credentials';
            echo json_encode($response);
            exit;
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION['user_id'] = (int)$admin['id'];
        $_SESSION['username'] = (string)$admin['username'];
        $_SESSION['full_name'] = (string)($admin['full_name'] ?: $admin['username']);
        $_SESSION['email'] = (string)($admin['email'] ?? '');
        $_SESSION['user_type'] = 'admin';
        $_SESSION['admin_portal'] = 'admin';
        $_SESSION['token'] = $token;

        $response['success'] = true;
        $response['message'] = 'Login successful';
        $response['data'] = [
            'id' => (int)$admin['id'],
            'username' => (string)$admin['username'],
            'full_name' => (string)($admin['full_name'] ?: $admin['username']),
            'email' => (string)($admin['email'] ?? ''),
            'user_type' => 'admin',
            'portal' => 'admin',
            'token' => $token,
        ];

        echo json_encode($response);
        exit;
    }

    $email = trim((string)($data['email'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));
    if (($email === '' && $phone === '') || $password === '') {
        $response['message'] = 'Email or phone number and password are required';
        echo json_encode($response);
        exit;
    }

    if ($email !== '') {
        $stmt = $conn->prepare('
            SELECT id, name, email, phone, password, is_active, is_authorized, is_blocked, must_change_password
            FROM marketers
            WHERE email = ? OR name = ?
            LIMIT 1
        ');
        $stmt->execute([$email, $email]);
    } else {
        $stmt = $conn->prepare('
            SELECT id, name, email, phone, password, is_active, is_authorized, is_blocked, must_change_password
            FROM marketers
            WHERE phone = ? OR name = ?
            LIMIT 1
        ');
        $stmt->execute([$phone, $phone]);
    }

    $marketer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$marketer) {
        $response['message'] = 'Invalid marketer credentials';
        echo json_encode($response);
        exit;
    }

    if (isset($marketer['is_active']) && (int)$marketer['is_active'] !== 1) {
        $response['message'] = 'Your account is inactive.';
        echo json_encode($response);
        exit;
    }

    if (isset($marketer['is_blocked']) && (int)$marketer['is_blocked'] === 1) {
        $response['message'] = 'Your account has been blocked. Please contact admin.';
        echo json_encode($response);
        exit;
    }

    if (!password_verify($password, (string)$marketer['password'])) {
        $response['message'] = 'Invalid marketer credentials';
        echo json_encode($response);
        exit;
    }

    $token = bin2hex(random_bytes(32));
    $marketerId = (int)$marketer['id'];

    $_SESSION['user_id'] = $marketerId;
    $_SESSION['username'] = (string)$marketer['name'];
    $_SESSION['full_name'] = (string)$marketer['name'];
    $_SESSION['user_type'] = 'marketer';
    $_SESSION['marketer_id'] = $marketerId;
    $_SESSION['email'] = (string)($marketer['email'] ?? '');
    $_SESSION['phone'] = (string)($marketer['phone'] ?? '');
    $_SESSION['token'] = $token;

    $response['success'] = true;
    $response['message'] = 'Login successful';
    $response['data'] = [
        'id' => $marketerId,
        'marketer_id' => $marketerId,
        'name' => (string)$marketer['name'],
        'email' => (string)($marketer['email'] ?? ''),
        'phone' => (string)($marketer['phone'] ?? ''),
        'user_type' => 'marketer',
        'token' => $token,
        'is_authorized' => isset($marketer['is_authorized']) ? (int)$marketer['is_authorized'] : 0,
        'is_blocked' => isset($marketer['is_blocked']) ? (int)$marketer['is_blocked'] : 0,
        'must_change_password' => isset($marketer['must_change_password']) ? (int)$marketer['must_change_password'] : 0,
    ];
} catch (Throwable $e) {
    error_log('Login Error: ' . $e->getMessage());
    $message = (string)$e->getMessage();
    $isMongoConnectionError = stripos($message, 'No suitable servers found') !== false
        || stripos($message, 'serverSelectionTryOnce') !== false
        || stripos($message, 'TLS handshake failed') !== false;
    $response['message'] = $isMongoConnectionError
        ? 'Unable to connect to the database right now. Please verify the Atlas network access list and try again.'
        : 'Error: ' . $message;
}

echo json_encode($response);
