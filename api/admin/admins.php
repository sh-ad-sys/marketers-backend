<?php
/**
 * Admin management API
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
    return [
        'id' => normalizeId($doc['_id'] ?? $doc['id'] ?? 0),
        'username' => (string)($doc['username'] ?? ''),
        'full_name' => (string)($doc['full_name'] ?? ''),
        'email' => (string)($doc['email'] ?? ''),
        'created_at' => mongoDateToString($doc['created_at'] ?? null),
    ];
}

try {
    if (!isAdminLoggedIn()) {
        $response['message'] = 'Admin access required';
        echo json_encode($response);
        exit;
    }

    if (apiUsesMongoStorage()) {
        require_once __DIR__ . '/../mongo/config.php';

        $db = mongoDb();
        $collection = $db->admins;

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

        $data = apiJsonInput() ?: $_REQUEST;
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

        $latestAdmin = $collection->findOne([], [
            'sort' => ['_id' => -1],
            'projection' => ['_id' => 1],
        ]);
        $newId = normalizeId($latestAdmin['_id'] ?? $latestAdmin['id'] ?? 0) + 1;
        $timestamp = mongoNow();

        $collection->insertOne([
            '_id' => $newId,
            'username' => $username,
            'full_name' => $fullName,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $createdAdmin = $collection->findOne(['_id' => $newId]);

        $response['success'] = true;
        $response['message'] = 'Admin created successfully';
        $response['data'] = adminApiMongoRow($createdAdmin ?: ['_id' => $newId, 'username' => $username, 'full_name' => $fullName, 'email' => $email, 'created_at' => $timestamp]);
        if ($generatedPassword !== '') {
            $response['data']['generated_password'] = $generatedPassword;
        }
        echo json_encode($response);
        exit;
    }

    $conn = apiMysql();

    if ($_SERVER['REQUEST_METHOD'] === 'GET' || (isset($_REQUEST['action']) && $_REQUEST['action'] === 'list')) {
        $admins = $conn->query('
            SELECT id, username, full_name, email, created_at
            FROM admins
            ORDER BY created_at DESC, id DESC
        ')->fetchAll(PDO::FETCH_ASSOC);

        $response['success'] = true;
        $response['data'] = array_map(
            static function (array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'username' => (string)$row['username'],
                    'full_name' => (string)($row['full_name'] ?? ''),
                    'email' => (string)($row['email'] ?? ''),
                    'created_at' => $row['created_at'] ?? null,
                ];
            },
            $admins
        );
        echo json_encode($response);
        exit;
    }

    $data = apiJsonInput() ?: $_REQUEST;
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

    $existingStmt = $conn->prepare('
        SELECT id
        FROM admins
        WHERE email = ? OR username = ?
        LIMIT 1
    ');
    $existingStmt->execute([$email, $username]);
    if ($existingStmt->fetch(PDO::FETCH_ASSOC)) {
        $response['message'] = 'An admin with that email or username already exists';
        echo json_encode($response);
        exit;
    }

    $stmt = $conn->prepare('
        INSERT INTO admins (username, password, full_name, email)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([
        $username,
        password_hash($password, PASSWORD_DEFAULT),
        $fullName,
        $email,
    ]);

    $response['success'] = true;
    $response['message'] = 'Admin created successfully';
    $response['data'] = [
        'id' => (int)$conn->lastInsertId(),
        'username' => $username,
        'full_name' => $fullName,
        'email' => $email,
        'created_at' => null,
    ];
    if ($generatedPassword !== '') {
        $response['data']['generated_password'] = $generatedPassword;
    }
} catch (Throwable $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log('Admins API Error: ' . $e->getMessage());
}

echo json_encode($response);
