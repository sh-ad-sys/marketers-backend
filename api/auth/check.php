<?php
/**
 * PlotConnect - Check Authentication API
 */

require_once dirname(__DIR__, 2) . '/config.php';

header('Content-Type: application/json');

// Only start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userType = getCurrentUserType();

if ($userType === 'admin') {
    echo json_encode([
        'success'   => true,
        'user_type' => 'admin',
        // Wrap in "data" so the frontend UserDashboard / AdminDashboard
        // can read res.data.name / res.data.username uniformly
        'data' => [
            'user_type' => 'admin',
            'username'  => $_SESSION['admin_username'] ?? 'admin',
            'name'      => $_SESSION['admin_username'] ?? 'admin',
        ]
    ]);
} elseif ($userType === 'marketer') {
    echo json_encode([
        'success'   => true,
        'user_type' => 'marketer',
        'data' => [
            'user_type' => 'marketer',
            'name'      => $_SESSION['marketer_name']  ?? '',
            'phone'     => $_SESSION['marketer_phone'] ?? '',
        ]
    ]);
} else {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Not authenticated'
    ]);
}