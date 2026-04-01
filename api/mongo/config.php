<?php
/**
 * MongoDB configuration and helpers for PlotConnect API.
 */

$mongoAutoload = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($mongoAutoload)) {
    require_once $mongoAutoload;
}

use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;

function mongoIsAvailable(): bool
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    $autoload = __DIR__ . '/../../vendor/autoload.php';
    $available = file_exists($autoload) && extension_loaded('mongodb') && class_exists(\MongoDB\Client::class);

    return $available;
}

function ensureMongoAvailable(): void
{
    if (!mongoIsAvailable()) {
        throw new Exception(
            'MongoDB backend is not available in this local environment. Run Composer install and enable the mongodb PHP extension.'
        );
    }
}

function loadPlotconnectEnv(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $envPath = dirname(__DIR__, 2) . '/.env';
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
            }
        }
    }

    $loaded = true;
}

function envOrDefault(string $key, string $default = ''): string
{
    loadPlotconnectEnv();
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }

    $value = trim((string)$value);
    return $value !== '' ? $value : $default;
}

function mongoBoolEnv(string $key, ?bool $default = null): ?bool
{
    $value = strtolower(envOrDefault($key, ''));
    if ($value === '') {
        return $default;
    }

    if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    return $default;
}

function mongoDefaultTlsCaFile(): string
{
    $configured = envOrDefault('MONGODB_TLS_CA_FILE');
    if ($configured !== '' && is_file($configured)) {
        return $configured;
    }

    foreach ([
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/ssl/cert.pem',
    ] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return '';
}

function mongoClientOptions(string $uri): array
{
    $options = [];
    $uriLower = strtolower($uri);
    $useTls = str_starts_with($uriLower, 'mongodb+srv://') || str_contains($uriLower, '.mongodb.net');
    $tlsOverride = mongoBoolEnv('MONGODB_TLS');
    if ($tlsOverride !== null) {
        $useTls = $tlsOverride;
    }

    if ($useTls) {
        $options['tls'] = true;
        $caFile = mongoDefaultTlsCaFile();
        if ($caFile !== '') {
            $options['tlsCAFile'] = $caFile;
        }
    }

    $timeout = (int)envOrDefault('MONGODB_SERVER_SELECTION_TIMEOUT_MS', '10000');
    if ($timeout > 0) {
        $options['serverSelectionTimeoutMS'] = $timeout;
    }

    return $options;
}

function mongoClient(): Client
{
    ensureMongoAvailable();

    static $client = null;
    if ($client instanceof Client) {
        return $client;
    }

    $uri = envOrDefault('MONGODB_URI', 'mongodb://127.0.0.1:27017');
    $client = new Client($uri, mongoClientOptions($uri));
    return $client;
}

function mongoDb()
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }

    $dbName = envOrDefault('MONGODB_DB', 'plotconnect');
    $db = mongoClient()->selectDatabase($dbName);
    return $db;
}

function mongoNow(): UTCDateTime
{
    ensureMongoAvailable();
    return new UTCDateTime((int)(microtime(true) * 1000));
}

function mongoDateFromValue($value): ?UTCDateTime
{
    if ($value instanceof UTCDateTime) {
        return $value;
    }
    if (!$value) {
        return null;
    }

    try {
        $dt = new DateTime((string)$value);
        return new UTCDateTime($dt->getTimestamp() * 1000);
    } catch (Throwable $e) {
        return null;
    }
}

function mongoAppTimezone(): DateTimeZone
{
    static $timezone = null;
    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }

    $configured = envOrDefault('APP_TIMEZONE', 'Africa/Nairobi');

    try {
        $timezone = new DateTimeZone($configured);
    } catch (Throwable $e) {
        $timezone = new DateTimeZone('Africa/Nairobi');
    }

    return $timezone;
}

function mongoDateToString($value): ?string
{
    if ($value instanceof UTCDateTime) {
        $dateTime = $value->toDateTime();
        $dateTime->setTimezone(mongoAppTimezone());
        return $dateTime->format(DateTimeInterface::ATOM);
    }
    return null;
}

function ensureSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_samesite', 'None');
        ini_set('session.cookie_secure', 'true');
        ini_set('session.cookie_httponly', 'true');
        session_start();
    }
}

function normalizeId($value): int
{
    return (int)$value;
}
