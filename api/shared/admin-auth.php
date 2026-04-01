<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/mongo/config.php';

function adminEnvValue(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value === false || $value === null || trim((string)$value) === '') {
        $value = $_ENV[$key] ?? $default;
    }

    return trim((string)$value);
}

function normalizeAdminPortal(string $portal = 'admin'): string
{
    return strtolower(trim($portal)) === 'ledger' ? 'ledger' : 'admin';
}

function currentAdminPortal(array $data = []): string
{
    ensureSession();

    $requestedPortal = (string)($data['portal'] ?? ($_SERVER['HTTP_X_AUTH_PORTAL'] ?? ($_SESSION['admin_portal'] ?? 'admin')));
    return normalizeAdminPortal($requestedPortal);
}

function adminPortalProfile(string $portal = 'admin'): array
{
    $normalizedPortal = normalizeAdminPortal($portal);

    $legacyAdminUser = adminEnvValue('ADMIN_USERNAME', '');
    $legacyAdminEmail = adminEnvValue('ADMIN_EMAIL', '');
    $legacyAdminPass = adminEnvValue('ADMIN_PASSWORD', '');
    $legacyAdminLooksLikeLedger =
        strtolower($legacyAdminUser) === 'ledger'
        || strtolower($legacyAdminEmail) === 'ledger@plotconnect.com';

    if ($normalizedPortal === 'ledger') {
        $username = adminEnvValue(
            'LEDGER_ADMIN_USERNAME',
            $legacyAdminLooksLikeLedger && $legacyAdminUser !== '' ? $legacyAdminUser : 'ledger'
        );
        $email = adminEnvValue(
            'LEDGER_ADMIN_EMAIL',
            $legacyAdminLooksLikeLedger && $legacyAdminEmail !== '' ? $legacyAdminEmail : 'ledger@plotconnect.com'
        );
        $password = adminEnvValue(
            'LEDGER_ADMIN_PASSWORD',
            $legacyAdminLooksLikeLedger && $legacyAdminPass !== '' ? $legacyAdminPass : ''
        );

        return [
            'portal' => 'ledger',
            'username' => $username !== '' ? $username : 'ledger',
            'email' => $email !== '' ? $email : 'ledger@plotconnect.com',
            'full_name' => 'Ledger Administrator',
            'password' => $password,
        ];
    }

    $defaultUsername = (!$legacyAdminLooksLikeLedger && $legacyAdminUser !== '') ? $legacyAdminUser : 'techswift-admin';
    $defaultEmail = (!$legacyAdminLooksLikeLedger && $legacyAdminEmail !== '') ? $legacyAdminEmail : 'techswifttrix361@gmail.com';
    $defaultPassword = (!$legacyAdminLooksLikeLedger && $legacyAdminPass !== '') ? $legacyAdminPass : '';

    return [
        'portal' => 'admin',
        'username' => adminEnvValue('PRIMARY_ADMIN_USERNAME', $defaultUsername),
        'email' => adminEnvValue('PRIMARY_ADMIN_EMAIL', $defaultEmail),
        'full_name' => 'Techswifttrix Admin',
        'password' => adminEnvValue('PRIMARY_ADMIN_PASSWORD', $defaultPassword),
    ];
}

function adminPortalIdentifiers(string $portal = 'admin'): array
{
    static $cache = [];
    $normalizedPortal = normalizeAdminPortal($portal);

    if (isset($cache[$normalizedPortal])) {
        return $cache[$normalizedPortal];
    }

    $profile = adminPortalProfile($normalizedPortal);
    $cache[$normalizedPortal] = [];

    foreach ([$profile['username'] ?? '', $profile['email'] ?? ''] as $value) {
        $normalizedValue = strtolower(trim((string)$value));
        if ($normalizedValue !== '' && !in_array($normalizedValue, $cache[$normalizedPortal], true)) {
            $cache[$normalizedPortal][] = $normalizedValue;
        }
    }

    return $cache[$normalizedPortal];
}

function adminIdentifierMatches(string $identifier, string $portal = 'admin'): bool
{
    $normalizedIdentifier = strtolower(trim($identifier));
    return $normalizedIdentifier !== '' && in_array($normalizedIdentifier, adminPortalIdentifiers($portal), true);
}

function adminCollection()
{
    $collection = mongoDb()->admins;
    $collection->createIndex(['username' => 1]);
    $collection->createIndex(['email' => 1]);
    $collection->createIndex(['portal' => 1]);

    return $collection;
}

function adminDocumentToArray($document): ?array
{
    if ($document === null) {
        return null;
    }

    if (is_array($document)) {
        return $document;
    }

    if (is_object($document) && method_exists($document, 'getArrayCopy')) {
        return $document->getArrayCopy();
    }

    return (array)$document;
}

function adminNextId($collection): int
{
    $last = $collection->findOne([], ['sort' => ['_id' => -1], 'projection' => ['_id' => 1]]);
    return $last ? (normalizeId($last['_id'] ?? 0) + 1) : 1;
}

function adminDocPortal(array $admin): string
{
    return normalizeAdminPortal((string)($admin['portal'] ?? 'admin'));
}

function findAdminByPortal(string $portal = 'admin'): ?array
{
    $collection = adminCollection();
    $normalizedPortal = normalizeAdminPortal($portal);

    $doc = adminDocumentToArray($collection->findOne(['portal' => $normalizedPortal]));
    if ($doc) {
        return $doc;
    }

    $profile = adminPortalProfile($normalizedPortal);
    $cursor = $collection->find([
        '$or' => [
            ['username' => (string)($profile['username'] ?? '')],
            ['email' => (string)($profile['email'] ?? '')],
        ],
    ], ['sort' => ['_id' => -1], 'limit' => 5]);

    foreach ($cursor as $candidate) {
        $row = adminDocumentToArray($candidate);
        if ($row) {
            return $row;
        }
    }

    return null;
}

function findAdminByIdentifier(string $identifier, string $portal = 'admin'): ?array
{
    $collection = adminCollection();
    $normalizedPortal = normalizeAdminPortal($portal);
    $lookup = trim($identifier);

    if ($lookup === '') {
        return $normalizedPortal === 'ledger' ? findAdminByPortal('ledger') : null;
    }

    $cursor = $collection->find([
        '$or' => [
            ['username' => $lookup],
            ['email' => $lookup],
        ],
    ], ['sort' => ['_id' => -1], 'limit' => 10]);

    foreach ($cursor as $candidate) {
        $row = adminDocumentToArray($candidate);
        if ($row && adminDocPortal($row) === $normalizedPortal) {
            return $row;
        }
    }

    return $normalizedPortal === 'ledger' ? findAdminByPortal('ledger') : null;
}

function findAdminByEmail(string $email, string $portal = 'admin'): ?array
{
    $normalizedPortal = normalizeAdminPortal($portal);
    $lookup = trim($email);
    if ($lookup === '') {
        return null;
    }

    $cursor = adminCollection()->find(['email' => $lookup], ['sort' => ['_id' => -1], 'limit' => 10]);
    foreach ($cursor as $candidate) {
        $row = adminDocumentToArray($candidate);
        if ($row && adminDocPortal($row) === $normalizedPortal) {
            return $row;
        }
    }

    return $normalizedPortal === 'ledger' ? findAdminByPortal('ledger') : null;
}

function findAdminById(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    return adminDocumentToArray(adminCollection()->findOne(['_id' => $id]));
}

function ensureConfiguredAdminAccount(string $portal = 'admin'): ?array
{
    $normalizedPortal = normalizeAdminPortal($portal);
    $profile = adminPortalProfile($normalizedPortal);
    $passwordHash = trim((string)($profile['password'] ?? ''));

    if ($passwordHash === '') {
        return findAdminByPortal($normalizedPortal);
    }

    $collection = adminCollection();
    $existing = findAdminByPortal($normalizedPortal);
    $adminId = normalizeId($existing['_id'] ?? 0);
    $now = mongoNow();

    if ($adminId <= 0) {
        $adminId = adminNextId($collection);
    }

    $update = [
        'portal' => $normalizedPortal,
        'username' => (string)($profile['username'] ?? ''),
        'email' => (string)($profile['email'] ?? ''),
        'full_name' => (string)($profile['full_name'] ?? 'Administrator'),
        'updated_at' => $now,
    ];

    if (!$existing) {
        $update['password'] = $passwordHash;
        $update['is_locked'] = 0;
        $update['failed_login_attempts'] = 0;
        $update['lock_reason'] = null;
        $update['locked_at'] = null;
        $update['created_at'] = $now;
    }

    $collection->updateOne(
        ['_id' => $adminId],
        ['$set' => $update],
        ['upsert' => true]
    );

    return findAdminById($adminId);
}

function verifyConfiguredAdminPassword(string $identifier, string $password, string $portal = 'admin'): bool
{
    $profile = adminPortalProfile($portal);
    $passwordHash = trim((string)($profile['password'] ?? ''));

    return $passwordHash !== ''
        && adminIdentifierMatches($identifier, $portal)
        && password_verify($password, $passwordHash);
}

function adminLockThreshold(): int
{
    return 3;
}

function loginAttemptShouldLock(int $nextFailedAttempts): bool
{
    return $nextFailedAttempts > adminLockThreshold();
}

function recordAdminFailedLogin(array $admin, bool $allowLock = true): array
{
    $collection = adminCollection();
    $adminId = normalizeId($admin['_id'] ?? $admin['id'] ?? 0);
    $nextAttempts = (int)($admin['failed_login_attempts'] ?? 0) + 1;
    $shouldLock = $allowLock && loginAttemptShouldLock($nextAttempts);
    $update = [
        'failed_login_attempts' => $nextAttempts,
        'updated_at' => mongoNow(),
    ];

    if ($shouldLock) {
        $update['is_locked'] = 1;
        $update['lock_reason'] = 'failed_attempts';
        $update['locked_at'] = mongoNow();
    }

    $collection->updateOne(['_id' => $adminId], ['$set' => $update]);

    $admin['failed_login_attempts'] = $nextAttempts;
    if ($shouldLock) {
        $admin['is_locked'] = 1;
        $admin['lock_reason'] = 'failed_attempts';
    }

    return $admin;
}

function resetAdminLoginAttempts(int $adminId): void
{
    if ($adminId <= 0) {
        return;
    }

    adminCollection()->updateOne(
        ['_id' => $adminId],
        ['$set' => [
            'failed_login_attempts' => 0,
            'updated_at' => mongoNow(),
            'is_locked' => 0,
            'lock_reason' => null,
            'locked_at' => null,
        ]]
    );
}

function adminPortalErrorMessage(string $portal = 'admin'): string
{
    return normalizeAdminPortal($portal) === 'ledger'
        ? 'Ledger account is locked. Please contact support.'
        : 'Admin account is locked. Ledger must unlock it.';
}

function currentAdminIdentifier(): string
{
    ensureSession();

    foreach ([
        $_SERVER['HTTP_X_AUTH_USER'] ?? '',
        $_SESSION['email'] ?? '',
        $_SESSION['username'] ?? '',
    ] as $candidate) {
        $value = trim((string)$candidate);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}
