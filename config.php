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

function envValueOrDefault(string $key, string $default, array $placeholderValues = []): string {
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }

    $value = trim((string)$value);
    if ($value === '') {
        return $default;
    }

    foreach ($placeholderValues as $placeholder) {
        if (strcasecmp($value, $placeholder) === 0) {
            return $default;
        }
    }

    if (preg_match('/^(your_|change_me|example_|replace_me)/i', $value)) {
        return $default;
    }

    return $value;
}

function envValueOrNull(string $key, array $placeholderValues = []): ?string {
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return null;
    }

    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    foreach ($placeholderValues as $placeholder) {
        if (strcasecmp($value, $placeholder) === 0) {
            return null;
        }
    }

    if (preg_match('/^(your_|change_me|example_|replace_me)/i', $value)) {
        return null;
    }

    return $value;
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
$dbHost = envValueOrDefault('DB_HOST', 'plotconnect-shadrackmutua081-64f3.k.aivencloud.com');
$dbPort = envValueOrDefault('DB_PORT', '27258');
$dbName = envValueOrDefault('DB_NAME', 'defaultdb');
$dbUser = envValueOrDefault('DB_USER', 'avnadmin');
$dbPass = envValueOrDefault('DB_PASS', 'AVNS_Q-OTx-X8_9pxJLFsNY4', ['your_password_here']);

if (!defined('DB_HOST')) define('DB_HOST', $dbHost);
if (!defined('DB_PORT')) define('DB_PORT', $dbPort);
if (!defined('DB_NAME')) define('DB_NAME', $dbName);
if (!defined('DB_USER')) define('DB_USER', $dbUser);
if (!defined('DB_PASS')) define('DB_PASS', $dbPass);

if (!defined('DB_SSL_MODE')) define('DB_SSL_MODE', 'REQUIRED');
if (!defined('DB_SSL_CA'))   define('DB_SSL_CA',   __DIR__ . '/ca.pem');

// ── Admin credentials ─────────────────────────────────────────────────────────
// Support separate admin and ledger credentials while remaining compatible
// with older deployments that only set ADMIN_* for the ledger portal.
$legacyAdminUser = envValueOrNull('ADMIN_USERNAME');
$legacyAdminEmail = envValueOrNull('ADMIN_EMAIL');
$legacyAdminPass = envValueOrNull('ADMIN_PASSWORD', ['your_hashed_password_here']);
$legacyAdminLooksLikeLedger =
    strtolower((string)$legacyAdminUser) === 'ledger'
    || strtolower((string)$legacyAdminEmail) === 'ledger@plotconnect.com';

$adminUser = envValueOrDefault(
    'PRIMARY_ADMIN_USERNAME',
    !$legacyAdminLooksLikeLedger && $legacyAdminUser !== null ? $legacyAdminUser : 'techswift-admin'
);
$adminEmail = envValueOrDefault(
    'PRIMARY_ADMIN_EMAIL',
    !$legacyAdminLooksLikeLedger && $legacyAdminEmail !== null ? $legacyAdminEmail : 'techswifttrix361@gmail.com'
);
$adminPass = envValueOrDefault(
    'PRIMARY_ADMIN_PASSWORD',
    !$legacyAdminLooksLikeLedger && $legacyAdminPass !== null
        ? $legacyAdminPass
        : '$2y$10$rHG5kf0ec8HNy3VF4UPC4u0L1ZFijuwOJ5.PzRHWCqN6JNmSl2t2u',
    ['your_hashed_password_here']
);

$ledgerAdminUser = envValueOrDefault(
    'LEDGER_ADMIN_USERNAME',
    $legacyAdminLooksLikeLedger && $legacyAdminUser !== null ? $legacyAdminUser : 'ledger'
);
$ledgerAdminEmail = envValueOrDefault(
    'LEDGER_ADMIN_EMAIL',
    $legacyAdminLooksLikeLedger && $legacyAdminEmail !== null ? $legacyAdminEmail : 'ledger@plotconnect.com'
);
$ledgerAdminPass = envValueOrDefault(
    'LEDGER_ADMIN_PASSWORD',
    $legacyAdminLooksLikeLedger && $legacyAdminPass !== null
        ? $legacyAdminPass
        : '$2y$10$Tq9uK4B2sus4WwzuUzUyUu/SwV7oyVWX73cOTJGGq6LUbzsiz/H9W',
    ['your_hashed_password_here']
);

if (!defined('PRIMARY_ADMIN_USERNAME')) define('PRIMARY_ADMIN_USERNAME', $adminUser);
if (!defined('PRIMARY_ADMIN_EMAIL')) define('PRIMARY_ADMIN_EMAIL', $adminEmail);
if (!defined('PRIMARY_ADMIN_PASSWORD')) define('PRIMARY_ADMIN_PASSWORD', $adminPass);
if (!defined('LEDGER_ADMIN_USERNAME')) define('LEDGER_ADMIN_USERNAME', $ledgerAdminUser);
if (!defined('LEDGER_ADMIN_EMAIL')) define('LEDGER_ADMIN_EMAIL', $ledgerAdminEmail);
if (!defined('LEDGER_ADMIN_PASSWORD')) define('LEDGER_ADMIN_PASSWORD', $ledgerAdminPass);

// Keep legacy constants mapped to the main admin portal.
if (!defined('ADMIN_USERNAME')) define('ADMIN_USERNAME', $adminUser);
if (!defined('ADMIN_EMAIL')) define('ADMIN_EMAIL', $adminEmail);
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
        ];

        // Some PHP builds expose PDO but not the MySQL SSL class constants.
        // Add SSL options only when the driver supports them to avoid fatals.
        if (defined('PDO::MYSQL_ATTR_SSL_CA') && is_file(DB_SSL_CA)) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = DB_SSL_CA;
        }

        if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        error_log('DB Connection Error: ' . $e->getMessage());
        throw new Exception('Database connection failed.');
    }

    return $pdo;
}

function normalizeAdminPortal(string $portal = 'admin'): string {
    return strtolower(trim($portal)) === 'ledger' ? 'ledger' : 'admin';
}

function adminPortalConfig(string $portal = 'admin'): array {
    $normalizedPortal = normalizeAdminPortal($portal);

    if ($normalizedPortal === 'ledger') {
        $username = trim((string)LEDGER_ADMIN_USERNAME);
        $email = trim((string)LEDGER_ADMIN_EMAIL);

        return [
            'username' => $username !== '' ? $username : 'ledger',
            'email' => $email,
            'full_name' => 'Ledger Administrator',
            'password' => (string)LEDGER_ADMIN_PASSWORD,
        ];
    }

    $username = trim((string)PRIMARY_ADMIN_USERNAME);
    $email = trim((string)PRIMARY_ADMIN_EMAIL);

    return [
        'username' => $username !== '' ? $username : 'admin',
        'email' => $email,
        'full_name' => 'Techswifttrix Admin',
        'password' => (string)PRIMARY_ADMIN_PASSWORD,
    ];
}

function adminLoginIdentifiers(string $portal = 'admin'): array {
    static $identifiers = [];
    $normalizedPortal = normalizeAdminPortal($portal);

    if (isset($identifiers[$normalizedPortal])) {
        return $identifiers[$normalizedPortal];
    }

    $profile = adminPortalConfig($normalizedPortal);
    $identifiers[$normalizedPortal] = [];

    foreach ([$profile['username'] ?? '', $profile['email'] ?? ''] as $value) {
        $normalized = strtolower(trim((string)$value));
        if ($normalized !== '' && !in_array($normalized, $identifiers[$normalizedPortal], true)) {
            $identifiers[$normalizedPortal][] = $normalized;
        }
    }

    return $identifiers[$normalizedPortal];
}

function adminIdentifierMatches($identifier, string $portal = 'admin'): bool {
    $normalized = strtolower(trim((string)$identifier));
    return $normalized !== '' && in_array($normalized, adminLoginIdentifiers($portal), true);
}

function adminDefaultProfile(string $portal = 'admin'): array {
    $profile = adminPortalConfig($portal);

    return [
        'username' => (string)($profile['username'] ?? ''),
        'email' => (string)($profile['email'] ?? ''),
        'full_name' => (string)($profile['full_name'] ?? 'Administrator'),
    ];
}

function adminPasswordHash(string $portal = 'admin'): string {
    $profile = adminPortalConfig($portal);
    return trim((string)($profile['password'] ?? ''));
}

function verifyAdmin($username, $password, string $portal = 'admin') {
    $hash = adminPasswordHash($portal);
    return $hash !== '' && adminIdentifierMatches($username, $portal) && password_verify($password, $hash);
}
