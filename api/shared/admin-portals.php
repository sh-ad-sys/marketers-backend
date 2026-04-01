<?php

require_once __DIR__ . '/storage.php';

function apiMongoGetAppSettingValue($db, string $key, ?string $default = null): ?string
{
    $setting = $db->app_settings->findOne(['_id' => $key]);

    if (!$setting) {
        return $default;
    }

    if (!array_key_exists('value', (array)$setting) || $setting['value'] === null) {
        return null;
    }

    return (string)$setting['value'];
}

function apiMongoSetAppSettingValue($db, string $key, ?string $value): void
{
    $db->app_settings->updateOne(
        ['_id' => $key],
        ['$set' => ['value' => $value, 'updated_at' => mongoNow()]],
        ['upsert' => true]
    );
}

function apiAdminPortalPasswordSettingKey(string $portal = 'admin'): string
{
    $normalizedPortal = function_exists('normalizeAdminPortal')
        ? normalizeAdminPortal($portal)
        : 'admin';

    return 'admin_portal_password_hash_' . $normalizedPortal;
}

function apiAdminPortalProfile(string $portal = 'admin'): array
{
    if (function_exists('adminDefaultProfile')) {
        return adminDefaultProfile($portal);
    }

    $normalizedPortal = function_exists('normalizeAdminPortal')
        ? normalizeAdminPortal($portal)
        : 'admin';

    if ($normalizedPortal === 'ledger') {
        return [
            'username' => 'ledger',
            'email' => 'ledger@plotconnect.com',
            'full_name' => 'Ledger Administrator',
        ];
    }

    return [
        'username' => 'admin',
        'email' => '',
        'full_name' => 'Administrator',
    ];
}

function apiAdminPortalConfiguredEmail(string $portal = 'admin'): string
{
    return strtolower(trim((string)(apiAdminPortalProfile($portal)['email'] ?? '')));
}

function apiAdminPortalEmailMatches(string $email, string $portal = 'admin'): bool
{
    $configuredEmail = apiAdminPortalConfiguredEmail($portal);
    $normalizedEmail = strtolower(trim($email));

    return $configuredEmail !== '' && $normalizedEmail !== '' && $configuredEmail === $normalizedEmail;
}

function apiAdminPortalEffectivePasswordHash(string $portal = 'admin'): string
{
    $normalizedPortal = function_exists('normalizeAdminPortal')
        ? normalizeAdminPortal($portal)
        : 'admin';
    $fallbackHash = function_exists('adminPasswordHash')
        ? trim((string)adminPasswordHash($normalizedPortal))
        : '';

    try {
        if (apiUsesMongoStorage()) {
            require_once dirname(__DIR__) . '/mongo/config.php';
            $db = mongoDb();
            $overrideHash = trim((string)apiMongoGetAppSettingValue($db, apiAdminPortalPasswordSettingKey($normalizedPortal), ''));
        } else {
            $overrideHash = trim((string)apiGetAppSetting(apiMysql(), apiAdminPortalPasswordSettingKey($normalizedPortal), ''));
        }

        return $overrideHash !== '' ? $overrideHash : $fallbackHash;
    } catch (Throwable $e) {
        error_log('Admin portal password lookup failed: ' . $e->getMessage());
        return $fallbackHash;
    }
}

function apiPersistAdminPortalPasswordHash(string $portal, string $passwordHash): void
{
    $normalizedPortal = function_exists('normalizeAdminPortal')
        ? normalizeAdminPortal($portal)
        : 'admin';
    $settingKey = apiAdminPortalPasswordSettingKey($normalizedPortal);

    if (apiUsesMongoStorage()) {
        require_once dirname(__DIR__) . '/mongo/config.php';
        $db = mongoDb();
        apiMongoSetAppSettingValue($db, $settingKey, $passwordHash);
        return;
    }

    apiSetAppSetting(apiMysql(), $settingKey, $passwordHash);
}

function apiVerifyAdminPortalPassword(string $identifier, string $password, string $portal = 'admin'): bool
{
    if (!function_exists('adminIdentifierMatches') || !adminIdentifierMatches($identifier, $portal)) {
        return false;
    }

    $hash = apiAdminPortalEffectivePasswordHash($portal);
    return $hash !== '' && password_verify($password, $hash);
}

function apiResolveAdminPortalFromRequest(array $data = []): string
{
    apiEnsureSessionStarted();

    $requestedPortal = (string)($data['portal'] ?? ($_SESSION['admin_portal'] ?? 'admin'));

    return function_exists('normalizeAdminPortal')
        ? normalizeAdminPortal($requestedPortal)
        : (strtolower(trim($requestedPortal)) === 'ledger' ? 'ledger' : 'admin');
}

function apiCurrentAdminIdentifier(): string
{
    apiEnsureSessionStarted();

    foreach ([
        $_SERVER['HTTP_X_AUTH_USER'] ?? '',
        $_SESSION['email'] ?? '',
        $_SESSION['username'] ?? '',
    ] as $value) {
        $candidate = trim((string)$value);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return '';
}
