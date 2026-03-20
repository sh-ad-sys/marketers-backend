<?php

require_once dirname(__DIR__, 2) . '/config.php';

header('Content-Type: application/json');

session_start();

$userType = getCurrentUserType();

if ($userType === 'admin') {
    echo json_encode([
        'success' => true,
        'user_type' => 'admin',
        'username' => $_SESSION['admin_username']
    ]);
} elseif ($userType === 'marketer') {
    echo json_encode([
        'success' => true,
        'user_type' => 'marketer',
        'name' => $_SESSION['marketer_name'],
        'phone' => $_SESSION['marketer_phone']
    ]);
} else {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Not authenticated'
    ]);
}