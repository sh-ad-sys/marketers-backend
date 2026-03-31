<?php

require_once __DIR__ . '/storage.php';

function apiMpesaIsPlaceholderValue(string $value): bool
{
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        return false;
    }

    if (preg_match('/^(your_|change_me|example_|replace_me)/i', $normalized)) {
        return true;
    }

    return in_array($normalized, [
        'your-backend-domain.com',
        'example.com',
        'example.org',
        'example.net',
    ], true);
}

function apiMpesaUrlHasPlaceholderHost(string $value): bool
{
    $host = strtolower((string)(parse_url(trim($value), PHP_URL_HOST) ?? ''));
    if ($host === '') {
        return apiMpesaIsPlaceholderValue($value);
    }

    return apiMpesaIsPlaceholderValue($host);
}

function apiMpesaEnv(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? (getenv($key) ?: $default);
    $value = trim((string)$value);

    if ($value === '' || apiMpesaIsPlaceholderValue($value) || apiMpesaUrlHasPlaceholderHost($value)) {
        return $default;
    }

    return $value;
}

function apiMpesaBaseUrl(): string
{
    $baseUrl = apiMpesaEnv('MPESA_BASE_URL');
    if ($baseUrl !== '') {
        return rtrim($baseUrl, '/');
    }

    $env = strtolower(apiMpesaEnv('MPESA_ENV', 'sandbox'));
    return $env === 'production'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke';
}

function apiMpesaNormalizeHost(string $host): string
{
    $normalized = trim($host);
    if ($normalized === '') {
        return '';
    }

    if (preg_match('/^\[(.+)\](?::\d+)?$/', $normalized, $matches)) {
        return strtolower($matches[1]);
    }

    if (substr_count($normalized, ':') === 1 && preg_match('/^(.+):\d+$/', $normalized, $matches)) {
        return strtolower($matches[1]);
    }

    return strtolower($normalized);
}

function apiMpesaHostIsPublic(string $host): bool
{
    $normalized = apiMpesaNormalizeHost($host);
    if ($normalized === '') {
        return false;
    }

    if (in_array($normalized, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)) {
        return false;
    }

    if (str_ends_with($normalized, '.local') || str_ends_with($normalized, '.internal')) {
        return false;
    }

    if (filter_var($normalized, FILTER_VALIDATE_IP)) {
        return filter_var($normalized, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    return true;
}

function apiMpesaRequestOrigin(): string
{
    $scheme = '';
    $forwardedProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwardedProto !== '') {
        $scheme = strtolower(trim(explode(',', $forwardedProto)[0]));
    } elseif (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        $scheme = 'https';
    } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
        $scheme = strtolower((string)$_SERVER['REQUEST_SCHEME']);
    } elseif ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') {
        $scheme = 'https';
    }

    $host = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? '')));
    if ($scheme === '' || $host === '') {
        return '';
    }

    return $scheme . '://' . $host;
}

function apiMpesaResolveAppUrl(): string
{
    $appUrl = apiMpesaEnv('APP_URL');
    if ($appUrl !== '') {
        $parts = parse_url($appUrl);
        $host = (string)($parts['host'] ?? '');
        $scheme = strtolower((string)($parts['scheme'] ?? ''));

        if ($scheme === 'https' && apiMpesaHostIsPublic($host)) {
            return rtrim($appUrl, '/');
        }
    }

    $renderExternalUrl = apiMpesaEnv('RENDER_EXTERNAL_URL');
    if ($renderExternalUrl !== '' && preg_match('/^https:\/\//i', $renderExternalUrl)) {
        return rtrim($renderExternalUrl, '/');
    }

    $origin = apiMpesaRequestOrigin();
    if ($origin === '') {
        return '';
    }

    $parts = parse_url($origin);
    $host = (string)($parts['host'] ?? '');
    $scheme = strtolower((string)($parts['scheme'] ?? ''));

    if ($scheme !== 'https' || !apiMpesaHostIsPublic($host)) {
        return '';
    }

    return rtrim($origin, '/');
}

function apiMpesaResolveCallbackUrl(): string
{
    $callbackUrl = apiMpesaEnv('MPESA_CALLBACK_URL');
    if ($callbackUrl !== '') {
        $parts = parse_url($callbackUrl);
        $host = (string)($parts['host'] ?? '');
        $scheme = strtolower((string)($parts['scheme'] ?? ''));

        if ($scheme === 'https' && apiMpesaHostIsPublic($host)) {
            return $callbackUrl;
        }
    }

    $appUrl = apiMpesaResolveAppUrl();
    if ($appUrl === '') {
        return '';
    }

    $callbackPath = apiMpesaEnv('MPESA_CALLBACK_PATH', '/api/marketer/mpesa-callback.php');
    return $appUrl . '/' . ltrim($callbackPath, '/');
}

function apiMpesaConfig(bool $requireCallback = true): array
{
    return [
        'base_url' => apiMpesaBaseUrl(),
        'consumer_key' => apiMpesaEnv('MPESA_CONSUMER_KEY'),
        'consumer_secret' => apiMpesaEnv('MPESA_CONSUMER_SECRET'),
        'shortcode' => apiMpesaEnv('MPESA_SHORTCODE'),
        'passkey' => apiMpesaEnv('MPESA_PASSKEY'),
        'callback_url' => $requireCallback ? apiMpesaResolveCallbackUrl() : apiMpesaEnv('MPESA_CALLBACK_URL'),
        'transaction_type' => apiMpesaEnv('MPESA_TRANSACTION_TYPE', 'CustomerPayBillOnline'),
    ];
}

function apiMpesaValidateConfig(array $config, bool $requireCallback = true): ?string
{
    $requiredFields = ['consumer_key', 'consumer_secret', 'shortcode', 'passkey'];
    if ($requireCallback) {
        $requiredFields[] = 'callback_url';
    }

    foreach ($requiredFields as $field) {
        if (trim((string)($config[$field] ?? '')) === '') {
            if ($field === 'callback_url') {
                return 'Missing MPesa configuration: callback_url. Set APP_URL or MPESA_CALLBACK_URL to your real public HTTPS backend URL.';
            }

            return "Missing MPesa configuration: {$field}";
        }
    }

    if ($requireCallback) {
        $callbackUrl = trim((string)$config['callback_url']);
        $host = (string)(parse_url($callbackUrl, PHP_URL_HOST) ?? '');

        if (apiMpesaUrlHasPlaceholderHost($callbackUrl)) {
            return 'MPESA callback URL still points to a placeholder domain. Set APP_URL or MPESA_CALLBACK_URL to your real public HTTPS backend URL.';
        }

        if (!preg_match('/^https:\/\//i', $callbackUrl) || !apiMpesaHostIsPublic($host)) {
            return 'MPESA_CALLBACK_URL must be a public HTTPS URL';
        }
    }

    return null;
}

function apiMpesaHttpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $headers[] = 'Accept: application/json';
    $responseBody = '';
    $statusCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = (string)curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            throw new Exception('MPesa request failed: ' . $error);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'ignore_errors' => true,
                'timeout' => 30,
            ],
        ]);

        $responseBody = (string)file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
            $statusCode = (int)$matches[1];
        }
    }

    $decoded = json_decode($responseBody, true);

    return [
        'status' => $statusCode,
        'body' => $responseBody,
        'json' => is_array($decoded) ? $decoded : [],
    ];
}

function apiMpesaResponseMessage(array $response, string $fallback = 'Unable to complete MPesa request'): string
{
    $json = $response['json'] ?? [];

    foreach (['errorMessage', 'ResultDesc', 'ResponseDescription'] as $field) {
        $message = trim((string)($json[$field] ?? ''));
        if ($message !== '') {
            return $message;
        }
    }

    $body = trim((string)($response['body'] ?? ''));
    return $body !== '' ? $body : $fallback;
}

function apiMpesaAccessToken(array $config): string
{
    $authHeader = 'Authorization: Basic ' . base64_encode($config['consumer_key'] . ':' . $config['consumer_secret']);
    $tokenResponse = apiMpesaHttpRequest(
        'GET',
        $config['base_url'] . '/oauth/v1/generate?grant_type=client_credentials',
        [$authHeader]
    );

    $accessToken = trim((string)($tokenResponse['json']['access_token'] ?? ''));
    if ($tokenResponse['status'] >= 400 || $accessToken === '') {
        throw new Exception(apiMpesaResponseMessage($tokenResponse, 'Unable to get MPesa access token'));
    }

    return $accessToken;
}

function apiMpesaAccessTokenCached(array $config): string
{
    static $cache = [];

    $cacheKey = md5(implode('|', [
        (string)($config['base_url'] ?? ''),
        (string)($config['consumer_key'] ?? ''),
        (string)($config['consumer_secret'] ?? ''),
    ]));

    if (!isset($cache[$cacheKey])) {
        $cache[$cacheKey] = apiMpesaAccessToken($config);
    }

    return $cache[$cacheKey];
}

function apiMpesaTimestamp(): string
{
    return date('YmdHis');
}

function apiMpesaPassword(array $config, string $timestamp): string
{
    return base64_encode($config['shortcode'] . $config['passkey'] . $timestamp);
}

function apiMpesaShouldSyncPendingProperty($property): bool
{
    $paymentStatus = strtolower(trim((string)($property['payment_status'] ?? '')));
    $checkoutRequestId = trim((string)($property['checkout_request_id'] ?? ''));

    return $paymentStatus === 'initiated' && $checkoutRequestId !== '';
}

function apiMpesaQueryState(array $queryResponse): array
{
    $responseCode = (string)($queryResponse['json']['ResponseCode'] ?? '');
    $resultCode = array_key_exists('ResultCode', $queryResponse['json'])
        ? (int)$queryResponse['json']['ResultCode']
        : null;
    $resultDesc = trim((string)($queryResponse['json']['ResultDesc'] ?? ($queryResponse['json']['ResponseDescription'] ?? '')));

    return [
        'response_code' => $responseCode,
        'result_code' => $resultCode,
        'result_desc' => $resultDesc,
    ];
}

function apiMpesaPendingStatusMessage(string $resultDesc): string
{
    return $resultDesc !== '' ? $resultDesc : 'Payment is still pending confirmation';
}

function apiMpesaTerminalStatusMessage(int $resultCode, string $resultDesc): string
{
    if ($resultCode === 0) {
        return $resultDesc !== '' ? $resultDesc : 'MPesa payment confirmed successfully.';
    }

    return $resultDesc !== '' ? $resultDesc : 'MPesa payment was not completed.';
}

function apiMpesaTrySyncMySqlPendingProperty(PDO $conn, array $property): array
{
    if (!apiMpesaShouldSyncPendingProperty($property)) {
        return $property;
    }

    $config = apiMpesaConfig(false);
    if (apiMpesaValidateConfig($config, false) !== null) {
        return $property;
    }

    $checkoutRequestId = trim((string)($property['checkout_request_id'] ?? ''));
    $propertyId = (int)($property['id'] ?? 0);
    if ($propertyId <= 0 || $checkoutRequestId === '') {
        return $property;
    }

    try {
        $accessToken = apiMpesaAccessTokenCached($config);
        $queryResponse = apiMpesaQueryStk($config, $accessToken, $checkoutRequestId);
        $queryState = apiMpesaQueryState($queryResponse);

        if (
            $queryResponse['status'] >= 400
            || (
                $queryState['response_code'] !== ''
                && $queryState['response_code'] !== '0'
                && $queryState['result_code'] === null
            )
        ) {
            return $property;
        }

        if ($queryState['result_code'] === null) {
            $pendingMessage = apiMpesaPendingStatusMessage($queryState['result_desc']);
            if ($pendingMessage !== (string)($property['payment_result_desc'] ?? '')) {
                $updateStmt = $conn->prepare('
                    UPDATE properties
                    SET payment_result_desc = ?
                    WHERE id = ?
                ');
                $updateStmt->execute([$pendingMessage, $propertyId]);
                $property['payment_result_desc'] = $pendingMessage;
            }

            return $property;
        }

        $message = apiMpesaTerminalStatusMessage($queryState['result_code'], $queryState['result_desc']);
        if ($queryState['result_code'] === 0) {
            $updateStmt = $conn->prepare('
                UPDATE properties
                SET payment_status = ?, payment_result_desc = ?, status = ?, paid_at = COALESCE(paid_at, ?)
                WHERE id = ?
            ');
            $updateStmt->execute([
                'completed',
                $message,
                'approved',
                date('Y-m-d H:i:s'),
                $propertyId,
            ]);
        } else {
            $updateStmt = $conn->prepare('
                UPDATE properties
                SET payment_status = ?, payment_result_desc = ?
                WHERE id = ?
            ');
            $updateStmt->execute([
                'failed',
                $message,
                $propertyId,
            ]);
        }

        $stmt = $conn->prepare('SELECT * FROM properties WHERE id = ? LIMIT 1');
        $stmt->execute([$propertyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: $property;
    } catch (Throwable $e) {
        error_log('MPesa display sync error (MySQL): ' . $e->getMessage());
        return $property;
    }
}

function apiMpesaTrySyncMongoPendingProperty($db, $property)
{
    if (!apiMpesaShouldSyncPendingProperty($property)) {
        return $property;
    }

    $config = apiMpesaConfig(false);
    if (apiMpesaValidateConfig($config, false) !== null) {
        return $property;
    }

    $checkoutRequestId = trim((string)($property['checkout_request_id'] ?? ''));
    $propertyId = function_exists('normalizeId') ? normalizeId($property['_id'] ?? 0) : 0;
    if ($propertyId <= 0 || $checkoutRequestId === '') {
        return $property;
    }

    try {
        $accessToken = apiMpesaAccessTokenCached($config);
        $queryResponse = apiMpesaQueryStk($config, $accessToken, $checkoutRequestId);
        $queryState = apiMpesaQueryState($queryResponse);

        if (
            $queryResponse['status'] >= 400
            || (
                $queryState['response_code'] !== ''
                && $queryState['response_code'] !== '0'
                && $queryState['result_code'] === null
            )
        ) {
            return $property;
        }

        if ($queryState['result_code'] === null) {
            $pendingMessage = apiMpesaPendingStatusMessage($queryState['result_desc']);
            if ($pendingMessage !== (string)($property['payment_result_desc'] ?? '')) {
                $db->properties->updateOne(
                    ['_id' => $propertyId],
                    ['$set' => ['payment_result_desc' => $pendingMessage]]
                );
            }

            return $db->properties->findOne(['_id' => $propertyId]) ?: $property;
        }

        $update = [
            'payment_result_desc' => apiMpesaTerminalStatusMessage($queryState['result_code'], $queryState['result_desc']),
        ];

        if ($queryState['result_code'] === 0) {
            $update['payment_status'] = 'completed';
            $update['status'] = 'approved';
            if (empty($property['paid_at'])) {
                $update['paid_at'] = mongoNow();
            }
        } else {
            $update['payment_status'] = 'failed';
        }

        $db->properties->updateOne(['_id' => $propertyId], ['$set' => $update]);

        return $db->properties->findOne(['_id' => $propertyId]) ?: $property;
    } catch (Throwable $e) {
        error_log('MPesa display sync error (Mongo): ' . $e->getMessage());
        return $property;
    }
}

function apiMpesaInitiateStk(array $config, string $accessToken, array $payload): array
{
    return apiMpesaHttpRequest(
        'POST',
        $config['base_url'] . '/mpesa/stkpush/v1/processrequest',
        [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        json_encode($payload, JSON_UNESCAPED_SLASHES)
    );
}

function apiMpesaQueryStk(array $config, string $accessToken, string $checkoutRequestId): array
{
    $timestamp = apiMpesaTimestamp();
    $password = apiMpesaPassword($config, $timestamp);

    return apiMpesaHttpRequest(
        'POST',
        $config['base_url'] . '/mpesa/stkpushquery/v1/query',
        [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        json_encode([
            'BusinessShortCode' => $config['shortcode'],
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ], JSON_UNESCAPED_SLASHES)
    );
}
