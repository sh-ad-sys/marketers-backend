<?php
/**
 * Admin MPesa Messages API
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

try {
    if (!isAdminLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    if (apiUsesMongoStorage()) {
        require_once __DIR__ . '/../mongo/config.php';

        $db = mongoDb();

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $marketerMap = [];
            foreach ($db->marketers->find([], ['projection' => ['_id' => 1, 'name' => 1, 'email' => 1]]) as $m) {
                $marketerMap[normalizeId($m['_id'])] = [
                    'name' => (string)($m['name'] ?? ''),
                    'email' => (string)($m['email'] ?? ''),
                ];
            }

            $messages = [];
            foreach ($db->mpesa_messages->find([], ['sort' => ['created_at' => -1, '_id' => -1]]) as $m) {
                $marketerId = normalizeId($m['marketer_id'] ?? 0);
                $mk = $marketerMap[$marketerId] ?? ['name' => 'Unknown', 'email' => ''];
                $messages[] = [
                    'id' => normalizeId($m['_id'] ?? 0),
                    'marketer_id' => $marketerId,
                    'marketer_name' => $mk['name'],
                    'marketer_email' => $mk['email'],
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
        $messageId = (int)($data['message_id'] ?? 0);
        $action = trim((string)($data['action'] ?? ''));

        if ($messageId <= 0 || $action === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }

        if ($action === 'delete') {
            $db->mpesa_messages->deleteOne(['_id' => $messageId]);
            echo json_encode(['success' => true, 'message' => 'Message deleted']);
            exit;
        }

        if (!in_array($action, ['approve', 'reject'], true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            exit;
        }

        $status = $action === 'approve' ? 'approved' : 'rejected';
        $db->mpesa_messages->updateOne(['_id' => $messageId], ['$set' => ['status' => $status]]);

        echo json_encode(['success' => true, 'message' => "Message {$status}"]);
        exit;
    }

    $conn = apiMysql();
    apiEnsureMpesaMessagesTable($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $orderColumn = apiMysqlOrderColumn($conn, 'mpesa_messages');
        $messages = $conn->query('
            SELECT mm.id,
                   mm.marketer_id,
                   COALESCE(m.name, \'Unknown\') AS marketer_name,
                   COALESCE(m.email, \'\') AS marketer_email,
                   mm.message_text,
                   COALESCE(mm.transaction_id, \'\') AS transaction_id,
                   mm.amount,
                   COALESCE(mm.status, \'pending\') AS status,
                   mm.created_at
            FROM mpesa_messages mm
            LEFT JOIN marketers m ON m.id = mm.marketer_id
            ORDER BY mm.' . $orderColumn . ' DESC, mm.id DESC
        ')->fetchAll(PDO::FETCH_ASSOC);

        $response['success'] = true;
        $response['data'] = array_map(
            static function (array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'marketer_id' => (int)$row['marketer_id'],
                    'marketer_name' => (string)($row['marketer_name'] ?? 'Unknown'),
                    'marketer_email' => (string)($row['marketer_email'] ?? ''),
                    'message_text' => (string)$row['message_text'],
                    'transaction_id' => (string)($row['transaction_id'] ?? ''),
                    'amount' => isset($row['amount']) ? (float)$row['amount'] : null,
                    'status' => (string)($row['status'] ?? 'pending'),
                    'created_at' => $row['created_at'] ?? null,
                ];
            },
            $messages
        );
        echo json_encode($response);
        exit;
    }

    $data = apiJsonInput();
    $messageId = (int)($data['message_id'] ?? 0);
    $action = trim((string)($data['action'] ?? ''));

    if ($messageId <= 0 || $action === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

    if ($action === 'delete') {
        $stmt = $conn->prepare('DELETE FROM mpesa_messages WHERE id = ?');
        $stmt->execute([$messageId]);
        echo json_encode(['success' => true, 'message' => 'Message deleted']);
        exit;
    }

    if (!in_array($action, ['approve', 'reject'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }

    $status = $action === 'approve' ? 'approved' : 'rejected';
    $stmt = $conn->prepare('UPDATE mpesa_messages SET status = ? WHERE id = ?');
    $stmt->execute([$status, $messageId]);

    echo json_encode(['success' => true, 'message' => "Message {$status}"]);
} catch (Throwable $e) {
    error_log('MPesa admin error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
