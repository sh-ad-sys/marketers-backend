<?php
/**
 * Admin Marketers API
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

function generateTemporaryPassword($length = 10)
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $password = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    return $password;
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
        $collection = $db->marketers;

        if ($_SERVER['REQUEST_METHOD'] === 'GET' || (isset($_REQUEST['action']) && $_REQUEST['action'] === 'list')) {
            $cursor = $collection->find([], ['sort' => ['created_at' => -1, '_id' => -1]]);
            $data = [];
            foreach ($cursor as $doc) {
                $data[] = [
                    'id' => normalizeId($doc['_id'] ?? 0),
                    'name' => (string)($doc['name'] ?? ''),
                    'phone' => (string)($doc['phone'] ?? ''),
                    'email' => (string)($doc['email'] ?? ''),
                    'is_active' => isset($doc['is_active']) ? (int)$doc['is_active'] : 1,
                    'is_authorized' => isset($doc['is_authorized']) ? (int)$doc['is_authorized'] : 0,
                    'is_blocked' => isset($doc['is_blocked']) ? (int)$doc['is_blocked'] : 0,
                    'must_change_password' => isset($doc['must_change_password']) ? (int)$doc['must_change_password'] : 0,
                    'created_at' => mongoDateToString($doc['created_at'] ?? null),
                ];
            }

            $response['success'] = true;
            $response['data'] = $data;
            echo json_encode($response);
            exit;
        }

        $data = apiJsonInput() ?: $_REQUEST;
        $action = trim((string)($data['action'] ?? ''));
        $id = isset($data['id']) ? (int)$data['id'] : 0;

        if ($action === 'delete') {
            $collection->deleteOne(['_id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Marketer deleted successfully']);
            exit;
        }

        if (in_array($action, ['authorize', 'reject', 'block', 'unblock'], true)) {
            $set = [];
            if ($action === 'authorize') {
                $set = ['is_authorized' => 1, 'is_blocked' => 0];
            } elseif ($action === 'reject') {
                $set = ['is_authorized' => 0];
            } elseif ($action === 'block') {
                $set = ['is_blocked' => 1, 'is_authorized' => 0];
            } elseif ($action === 'unblock') {
                $set = ['is_blocked' => 0];
            }

            $collection->updateOne(['_id' => $id], ['$set' => $set]);
            echo json_encode(['success' => true, 'message' => 'Marketer status updated successfully']);
            exit;
        }

        $name = trim((string)($data['name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $temporaryPassword = trim((string)($data['password'] ?? '')) ?: generateTemporaryPassword(10);

        if ($name === '' || $phone === '') {
            $response['message'] = 'Name and phone are required';
            echo json_encode($response);
            exit;
        }

        $newId = (int)(($collection->findOne([], ['sort' => ['_id' => -1], 'projection' => ['_id' => 1]])['_id'] ?? 0)) + 1;
        $collection->insertOne([
            '_id' => $newId,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => password_hash($temporaryPassword, PASSWORD_DEFAULT),
            'is_active' => 1,
            'is_authorized' => 0,
            'is_blocked' => 0,
            'must_change_password' => 1,
            'created_at' => mongoNow(),
        ]);

        $response['success'] = true;
        $response['message'] = 'Marketer added successfully. Share temporary password with marketer.';
        $response['data'] = ['id' => $newId, 'temporary_password' => $temporaryPassword];
        echo json_encode($response);
        exit;
    }

    $conn = apiMysql();

    if ($_SERVER['REQUEST_METHOD'] === 'GET' || (isset($_REQUEST['action']) && $_REQUEST['action'] === 'list')) {
        $orderColumn = apiMysqlOrderColumn($conn, 'marketers');
        $sql = '
            SELECT id, name, phone,
                   COALESCE(email, \'\') AS email,
                   COALESCE(is_active, 1) AS is_active,
                   COALESCE(is_authorized, 0) AS is_authorized,
                   COALESCE(is_blocked, 0) AS is_blocked,
                   COALESCE(must_change_password, 0) AS must_change_password' .
               (apiTableHasColumn($conn, 'marketers', 'created_at') ? ', created_at' : ', NULL AS created_at') . '
            FROM marketers
            ORDER BY ' . $orderColumn . ' DESC, id DESC';
        $marketers = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $response['success'] = true;
        $response['data'] = array_map(
            static function (array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'name' => (string)$row['name'],
                    'phone' => (string)$row['phone'],
                    'email' => (string)($row['email'] ?? ''),
                    'is_active' => (int)($row['is_active'] ?? 1),
                    'is_authorized' => (int)($row['is_authorized'] ?? 0),
                    'is_blocked' => (int)($row['is_blocked'] ?? 0),
                    'must_change_password' => (int)($row['must_change_password'] ?? 0),
                    'created_at' => $row['created_at'] ?? null,
                ];
            },
            $marketers
        );
        echo json_encode($response);
        exit;
    }

    $data = apiJsonInput() ?: $_REQUEST;
    $action = trim((string)($data['action'] ?? ''));
    $id = isset($data['id']) ? (int)$data['id'] : 0;

    if ($action === 'delete') {
        if ($id <= 0) {
            $response['message'] = 'Invalid marketer ID';
            echo json_encode($response);
            exit;
        }

        $stmt = $conn->prepare('DELETE FROM marketers WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Marketer deleted successfully']);
        exit;
    }

    if (in_array($action, ['authorize', 'reject', 'block', 'unblock'], true)) {
        if ($id <= 0) {
            $response['message'] = 'Invalid marketer ID';
            echo json_encode($response);
            exit;
        }

        $sql = '';
        if ($action === 'authorize') {
            $sql = 'UPDATE marketers SET is_authorized = 1, is_blocked = 0 WHERE id = ?';
        } elseif ($action === 'reject') {
            $sql = 'UPDATE marketers SET is_authorized = 0 WHERE id = ?';
        } elseif ($action === 'block') {
            $sql = 'UPDATE marketers SET is_blocked = 1, is_authorized = 0 WHERE id = ?';
        } elseif ($action === 'unblock') {
            $sql = 'UPDATE marketers SET is_blocked = 0 WHERE id = ?';
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Marketer status updated successfully']);
        exit;
    }

    $name = trim((string)($data['name'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));
    $temporaryPassword = trim((string)($data['password'] ?? '')) ?: generateTemporaryPassword(10);

    if ($name === '' || $phone === '') {
        $response['message'] = 'Name and phone are required';
        echo json_encode($response);
        exit;
    }

    $stmt = $conn->prepare('
        INSERT INTO marketers (name, email, phone, password, is_active, is_authorized, is_blocked, must_change_password)
        VALUES (?, ?, ?, ?, 1, 0, 0, 1)
    ');
    $stmt->execute([
        $name,
        $email,
        $phone,
        password_hash($temporaryPassword, PASSWORD_DEFAULT),
    ]);

    $response['success'] = true;
    $response['message'] = 'Marketer added successfully. Share temporary password with marketer.';
    $response['data'] = [
        'id' => (int)$conn->lastInsertId(),
        'temporary_password' => $temporaryPassword,
    ];
} catch (Throwable $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log('Marketers API Error: ' . $e->getMessage());
}

echo json_encode($response);
