<?php
/**
 * Test login endpoint
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$username = 'admin';
$password = 'password';

if ($username === ADMIN_USERNAME && password_verify($password, ADMIN_PASSWORD)) {
    echo json_encode(['success' => true, 'message' => 'Login would succeed']);
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'Login would fail',
        'debug' => [
            'ADMIN_USERNAME' => ADMIN_USERNAME,
            'ADMIN_PASSWORD' => ADMIN_PASSWORD,
            'password_match' => password_verify($password, ADMIN_PASSWORD)
        ]
    ]);
}
