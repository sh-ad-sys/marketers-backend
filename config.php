<?php
/**
 * PlotConnect - Database Configuration
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

// ── Load .env ────────────────────────────────────────────────────────────────
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $k = trim($key);
            $v = trim($value);
            $_ENV[$k] = $v;
            putenv("$k=$v");
        }
    }
}

// ── CORS ─────────────────────────────────────────────────────────────────────
$allowedOrigins = [
    "http://localhost:3000",
    "https://marketers-l984.vercel.app"
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Role, X-Auth-User");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ── Session ───────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);
    session_start();
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function jsonResponse($success, $message = null, $data = null, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

/**
 * Returns the authenticated role, checking BOTH the session cookie
 * AND the X-Auth-Role / X-Auth-User headers (for cross-origin requests
 * where cookies are blocked).
 */
function getCurrentUserType() {
    // 1. Session cookie (same-origin or if cookies work)
    if (!empty($_SESSION['user_type'])) {
        return $_SESSION['user_type'];
    }

    // 2. Header-based auth (cross-origin: Vercel → Render)
    $role = $_SERVER['HTTP_X_AUTH_ROLE'] ?? '';
    if (in_array($role, ['admin', 'marketer'], true)) {
        return $role;
    }

    return null;
}

function isAdminLoggedIn() {
    return getCurrentUserType() === 'admin';
}

function isMarketerLoggedIn() {
    return getCurrentUserType() === 'marketer';
}

// ── DB constants ──────────────────────────────────────────────────────────────
$dbHost = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'plotconnect-shadrackmutua081-64f3.k.aivencloud.com';
$dbPort = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '27258';
$dbName = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'defaultdb';
$dbUser = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'avnadmin';
$dbPass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: 'AVNS_Q-OTx-X8_9pxJLFsNY4';

if (!defined('DB_HOST')) define('DB_HOST', $dbHost);
if (!defined('DB_PORT')) define('DB_PORT', $dbPort);
if (!defined('DB_NAME')) define('DB_NAME', $dbName);
if (!defined('DB_USER')) define('DB_USER', $dbUser);
if (!defined('DB_PASS')) define('DB_PASS', $dbPass);

if (!defined('DB_SSL_MODE')) define('DB_SSL_MODE', 'REQUIRED');
if (!defined('DB_SSL_CA'))   define('DB_SSL_CA',   __DIR__ . '/ca.pem');

// ── Admin credentials ─────────────────────────────────────────────────────────
// Try multiple methods to get environment variables (works with Render)
$adminUser = getenv('ADMIN_USERNAME') ?: ($_ENV['ADMIN_USERNAME'] ?? 'admin');
$adminPass = trim(getenv('ADMIN_PASSWORD') ?: ($_ENV['ADMIN_PASSWORD'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'));

if (!defined('ADMIN_USERNAME')) define('ADMIN_USERNAME', $adminUser);
if (!defined('ADMIN_PASSWORD')) define('ADMIN_PASSWORD', $adminPass);

// ── DB Connection ─────────────────────────────────────────────────────────────
function getDBConnection() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST, DB_PORT, DB_NAME
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_SSL_CA       => DB_SSL_CA,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
        ];

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        error_log('DB Connection Error: ' . $e->getMessage());
        throw new Exception('Database connection failed.');
    }

    return $pdo;
}

function verifyAdmin($username, $password) {
    return $username === ADMIN_USERNAME && password_verify($password, ADMIN_PASSWORD);
}