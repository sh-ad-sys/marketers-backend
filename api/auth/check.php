<?php
/**
 * PlotConnect - Check Authentication API (Standalone)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Get user type from headers (header-based auth)
$userType = $_SERVER['HTTP_X_AUTH_ROLE'] ?? '';
$marketerId = $_SERVER['HTTP_X_AUTH_MARKETER_ID'] ?? '';

// Also check session if available
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$sessionUserType = $_SESSION['user_type'] ?? null;

// Use header-based auth or session auth
if ($userType === 'admin' || $sessionUserType === 'admin') {
    echo json_encode([
        'success'   => true,
        'user_type' => 'admin',
        'data' => [
            'user_type' => 'admin',
            'username'  => 'admin',
            'name'      => 'Administrator',
        ]
    ]);
} elseif ($userType === 'marketer' || $sessionUserType === 'marketer') {
    // If using header auth, we need to get the marketer details from DB
    $marketerId = $marketerId ?: ($_SESSION['marketer_id'] ?? null);
    
    if ($marketerId) {
        // Database connection - direct
        $dbHost = 'plotconnect-shadrackmutua081-64f3.k.aivencloud.com';
        $dbPort = '27258';
        $dbName = 'defaultdb';
        $dbUser = 'avnadmin';
        $dbPass = 'AVNS_Q-OTx-X8_9pxJLFsNY4';
        
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $dbHost, $dbPort, $dbName
            );
            $conn = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            
            $stmt = $conn->prepare("SELECT name, phone FROM marketers WHERE id = ?");
            $stmt->execute([$marketerId]);
            $marketer = $stmt->fetch();
            
            if ($marketer) {
                echo json_encode([
                    'success'   => true,
                    'user_type' => 'marketer',
                    'data' => [
                        'user_type' => 'marketer',
                        'name'      => $marketer['name'],
                        'phone'     => $marketer['phone'],
                        'marketer_id' => $marketerId
                    ]
                ]);
                exit;
            }
        } catch (PDOException $e) {
            error_log("DB Error in check.php: " . $e->getMessage());
        }
    }
    
    echo json_encode([
        'success'   => true,
        'user_type' => 'marketer',
        'data' => [
            'user_type' => 'marketer',
            'name'      => $_SESSION['marketer_name']  ?? 'Marketer',
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
