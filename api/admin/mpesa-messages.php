<?php
/**
 * Admin MPesa Messages API (MongoDB)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../mongo/config.php';

$response = ['success' => false, 'message' => '', 'data' => []];

try {
    $role = $_SERVER['HTTP_X_AUTH_ROLE'] ?? '';
    if ($role !== 'admin') {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

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

    $data = json_decode(file_get_contents('php://input'), true);
    $messageId = (int)($data['message_id'] ?? 0);
    $action = trim($data['action'] ?? '');

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
} catch (Throwable $e) {
    error_log('MPesa admin error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
