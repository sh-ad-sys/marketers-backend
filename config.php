<?php
/**
 * Database Configuration for PlotConnect
 * Aiven MySQL with SSL
 * 
 * NOTE: Secrets should be set via environment variables for security
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// JSON Response helper function
function jsonResponse($success, $message = null, $data = null, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

// Database credentials - use environment variables
define('DB_HOST', getenv('DB_HOST') ?: 'plotconnect-shadrackmutua081-64f3.k.aivencloud.com');
define('DB_PORT', getenv('DB_PORT') ?: '27258');
define('DB_NAME', getenv('DB_NAME') ?: 'defaultdb');
define('DB_USER', getenv('DB_USER') ?: 'avnadmin');
define('DB_PASS', getenv('DB_PASS') ?: '');

// SSL Configuration
define('DB_SSL_MODE', 'REQUIRED');
define('DB_SSL_CA', __DIR__ . '/ca.pem');

// Service URI (DSN) - constructed dynamically
function getServiceURI() {
    return sprintf(
        'mysql://%s:%s@%s:%s/%s?ssl-mode=%s',
        DB_USER,
        rawurlencode(DB_PASS),
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_SSL_MODE
    );
}

/**
 * Get PDO database connection
 * @return PDO
 */
function getDBConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;sslmode=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_SSL_MODE
            );
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_SSL_CA => DB_SSL_CA,
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
            ];
            
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('Database Connection Error: ' . $e->getMessage());
            throw new Exception('Database connection failed. Please check your configuration.');
        }
    }
    
    return $pdo;
}

// Admin credentials - use environment variables
define('ADMIN_USERNAME', getenv('ADMIN_USERNAME') ?: 'admin');
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD') ?: '');

/**
 * Verify admin credentials
 * @param string $username
 * @param string $password
 * @return bool
 */
function verifyAdmin($username, $password) {
    require_once __DIR__ . '/php/hash.php';
    
    if ($username === ADMIN_USERNAME && Hash::check($password, ADMIN_PASSWORD)) {
        return true;
    }
    return false;
}
