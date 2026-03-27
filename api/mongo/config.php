<?php
/**
 * MongoDB configuration and helpers for PlotConnect API.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;

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
    $value = $_ENV[$key] ?? '';
    return trim((string)$value) !== '' ? trim((string)$value) : $default;
}

function mongoClient(): Client
{
    static $client = null;
    if ($client instanceof Client) {
        return $client;
    }

    $uri = envOrDefault('MONGODB_URI', 'mongodb://127.0.0.1:27017');
    $client = new Client($uri);
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

function mongoDateToString($value): ?string
{
    if ($value instanceof UTCDateTime) {
        return $value->toDateTime()->format('Y-m-d H:i:s');
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
