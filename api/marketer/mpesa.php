<?php
/**
 * Marketer MPesa messages API
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../shared/storage.php';
apiEnsureSessionStarted();

$response = ['success' => false, 'message' => '', 'data' => []];

function nextMpesaId($collection)
{
    $last = $collection->findOne([], ['sort' => ['_id' => -1], 'projection' => ['_id' => 1]]);
    return $last ? (normalizeId($last['_id']) + 1) : 1;
}

function resolveMarketerId($db): int
{
    $marketerId = isset($_SERVER['HTTP_X_AUTH_MARKETER_ID']) ? (int)$_SERVER['HTTP_X_AUTH_MARKETER_ID'] : 0;
    if ($marketerId <= 0 && isset($_SESSION['marketer_id'])) {
        $marketerId = normalizeId($_SESSION['marketer_id']);
    }
    if ($marketerId <= 0) {
        $username = $_SERVER['HTTP_X_AUTH_USER'] ?? '';
        if ($username !== '') {
            $mk = $db->marketers->findOne(['$or' => [['name' => $username], ['email' => $username]]], ['projection' => ['_id' => 1]]);
            if ($mk) {
                $marketerId = normalizeId($mk['_id']);
            }
        }
    }
    return $marketerId;
}

try {
    if (apiUsesMongoStorage()) {
        require_once __DIR__ . '/../mongo/config.php';
        ensureSession();

        $db = mongoDb();
        $marketerId = resolveMarketerId($db);
        if ($marketerId <= 0) {
            $response['message'] = 'Authentication required';
            echo json_encode($response);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $messages = [];
            foreach ($db->mpesa_messages->find(['marketer_id' => $marketerId], ['sort' => ['created_at' => -1, '_id' => -1]]) as $m) {
                $messages[] = [
                    'id' => normalizeId($m['_id'] ?? 0),
                    'marketer_id' => $marketerId,
                    'message_text' => (string)($m['message_text'] ?? ''),
                    'transaction_id' => (string)($m['transaction_id'] ?? ''),
                    'amount' => $m['amount'] ?? null,
                    'status' => (string)($m['status'] ?? 'pending'),
                    'created_at' => mongoDateToString($m['created_at'] ?? null),
                ];
            }
            $response['success'] = true;
            $response['data'] = $messages;
            echo json_encode($response);
            exit;
        }

        $data = apiJsonInput();
        $messageText = trim((string)($data['message_text'] ?? ''));
        if ($messageText === '') {
            $response['message'] = 'message_text is required';
            echo json_encode($response);
            exit;
        }

        $id = nextMpesaId($db->mpesa_messages);
        $db->mpesa_messages->insertOne([
            '_id' => $id,
            'marketer_id' => $marketerId,
            'message_text' => $messageText,
            'transaction_id' => trim((string)($data['transaction_id'] ?? '')),
            'amount' => isset($data['amount']) && $data['amount'] !== '' ? (float)$data['amount'] : null,
            'status' => 'pending',
            'created_at' => mongoNow(),
        ]);

        $response['success'] = true;
        $response['message'] = 'MPesa message submitted';
        $response['data'] = ['id' => $id];
        echo json_encode($response);
        exit;
    }

    $conn = apiMysql();
    apiEnsureMpesaMessagesTable($conn);

    $marketerId = apiResolveMySqlMarketerId($conn);
    if ($marketerId <= 0) {
        $response['message'] = 'Authentication required';
        echo json_encode($response);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $orderColumn = apiMysqlOrderColumn($conn, 'mpesa_messages');
        $stmt = $conn->prepare('
            SELECT id, marketer_id, message_text,
                   COALESCE(transaction_id, \'\') AS transaction_id,
                   amount,
                   COALESCE(status, \'pending\') AS status,
                   created_at
            FROM mpesa_messages
            WHERE marketer_id = ?
            ORDER BY ' . $orderColumn . ' DESC, id DESC
        ');
        $stmt->execute([$marketerId]);

        $response['success'] = true;
        $response['data'] = array_map(
            static function (array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'marketer_id' => (int)$row['marketer_id'],
                    'message_text' => (string)$row['message_text'],
                    'transaction_id' => (string)($row['transaction_id'] ?? ''),
                    'amount' => isset($row['amount']) ? (float)$row['amount'] : null,
                    'status' => (string)($row['status'] ?? 'pending'),
                    'created_at' => $row['created_at'] ?? null,
                ];
            },
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
        echo json_encode($response);
        exit;
    }

    $data = apiJsonInput();
    $messageText = trim((string)($data['message_text'] ?? ''));
    if ($messageText === '') {
        $response['message'] = 'message_text is required';
        echo json_encode($response);
        exit;
    }

    $stmt = $conn->prepare('
        INSERT INTO mpesa_messages (marketer_id, message_text, transaction_id, amount, status)
        VALUES (?, ?, ?, ?, \'pending\')
    ');
    $stmt->execute([
        $marketerId,
        $messageText,
        trim((string)($data['transaction_id'] ?? '')),
        isset($data['amount']) && $data['amount'] !== '' ? (float)$data['amount'] : null,
    ]);

    $response['success'] = true;
    $response['message'] = 'MPesa message submitted';
    $response['data'] = ['id' => (int)$conn->lastInsertId()];
} catch (Throwable $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log('Marketer MPesa error: ' . $e->getMessage());
}

echo json_encode($response);
