<?php
/**
 * PlotConnect - Database Configuration
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

function normalizeEnvFileValue(string $value): string {
    $trimmed = trim($value);
    $length = strlen($trimmed);

    if ($length >= 2) {
        $first = $trimmed[0];
        $last = $trimmed[$length - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $trimmed = substr($trimmed, 1, -1);
        }
    }

    return $trimmed;
}

function envVarAlreadyDefined(string $key): bool {
    if (array_key_exists($key, $_ENV) && trim((string)$_ENV[$key]) !== '') {
        return true;
    }

    $value = getenv($key);
    return $value !== false && trim((string)$value) !== '';
}

function runningOnRender(): bool {
    foreach (['RENDER', 'RENDER_EXTERNAL_URL', 'RENDER_SERVICE_ID'] as $key) {
        $value = getenv($key);
        if ($value !== false && trim((string)$value) !== '') {
            return true;
        }
    }

    return false;
}

// ── Load .env ────────────────────────────────────────────────────────────────
// For local development, try multiple possible .env locations
$possibleEnvFiles = [
    __DIR__ . '/.env',
    __DIR__ . '/php/.env',
    dirname(__DIR__) . '/.env',
    dirname(__DIR__) . '/php/.env',
];

$envFile = null;
foreach ($possibleEnvFiles as $file) {
    if (file_exists($file)) {
        $envFile = $file;
        break;
    }
}

// Also check if we're on Render (environment variables are set in Render dashboard)
// If RENDER flag is set, skip .env file
if (!runningOnRender() && $envFile && file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $trimmedLine = trim($line);
        if ($trimmedLine === '' || strpos($trimmedLine, '#') === 0) {
            continue;
        }

        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $k = trim($key);

            if ($k === '' || !preg_match('/^[A-Z0-9_]+$/i', $k) || envVarAlreadyDefined($k)) {
                continue;
            }

            $v = normalizeEnvFileValue($value);
            $_ENV[$k] = $v;
            putenv("$k=$v");
        }
    }
}

// ── CORS ─────────────────────────────────────────────────────────────────────
// Allow all localhost ports for development and Vercel for production
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$isLocalhost = preg_match('/^http:\/\/localhost(:\d+)?$/', $origin);
$isVercel = strpos($origin, 'vercel.app') !== false || strpos($origin, 'vercel.com') !== false;

if ($isLocalhost || $isVercel || empty($origin)) {
    header("Access-Control-Allow-Origin: " . ($origin ?: "*"));
    header("Access-Control-Allow-Credentials: true");
} else {
    // Default: allow all for development
    header("Access-Control-Allow-Origin: *");
}

header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id, Accept");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ── Session ───────────────────────────────────────────────────────────────────
// For local development (HTTP), use less strict cookie settings
$isLocal = isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false;

if (session_status() === PHP_SESSION_NONE) {
    $cookieParams = [
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !$isLocal,  // false for localhost, true for production
        'httponly' => true,
        'samesite' => $isLocal ? 'Lax' : 'None'
    ];
    session_set_cookie_params($cookieParams);
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

    // 2. Header-based auth (cross-origin: Vercel → Render or local dev)
    $role = $_SERVER['HTTP_X_AUTH_ROLE'] ?? '';
    if (in_array($role, ['admin', 'marketer'], true)) {
        return $role;
    }

    // 3. Also check X-Auth-Marketer-Id header for session-less auth
    $marketerId = $_SERVER['HTTP_X_AUTH_MARKETER_ID'] ?? '';
    if (!empty($marketerId)) {
        $_SESSION['marketer_id'] = $marketerId;
        $_SESSION['user_type'] = 'marketer';
        return 'marketer';
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
