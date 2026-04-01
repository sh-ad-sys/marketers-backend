<?php

require_once dirname(__DIR__, 2) . '/config.php';

function apiUsesMongoStorage(): bool
{
    static $usesMongo = null;

    if ($usesMongo !== null) {
        return $usesMongo;
    }

    $preferredStorage = strtolower(trim((string)($_ENV['PLOTCONNECT_STORAGE'] ?? (getenv('PLOTCONNECT_STORAGE') ?: ''))));
    if ($preferredStorage === 'mysql') {
        return $usesMongo = false;
    }

    $mongoConfig = dirname(__DIR__) . '/mongo/config.php';
    if (!file_exists($mongoConfig)) {
        return $usesMongo = false;
    }

    require_once $mongoConfig;

    if (!function_exists('mongoIsAvailable')) {
        return $usesMongo = false;
    }

    $usesMongo = mongoIsAvailable();

    return $usesMongo;
}

function apiEnsureSessionStarted(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function apiJsonInput(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function apiMysql(): PDO
{
    return getDBConnection();
}

function apiTableColumns(PDO $conn, string $table, bool $refresh = false): array
{
    static $cache = [];
    $cacheKey = spl_object_id($conn) . ':' . $table;

    if ($refresh || !isset($cache[$cacheKey])) {
        $stmt = $conn->query('DESCRIBE ' . $table);
        $cache[$cacheKey] = array_map(
            static fn(array $row): string => (string)$row['Field'],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    return $cache[$cacheKey];
}

function apiRefreshTableColumns(PDO $conn, string $table): array
{
    return apiTableColumns($conn, $table, true);
}

function apiTableHasColumn(PDO $conn, string $table, string $column): bool
{
    return in_array($column, apiTableColumns($conn, $table), true);
}

function apiTableExists(PDO $conn, string $table): bool
{
    static $cache = [];

    $cacheKey = spl_object_id($conn) . ':' . $table;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $stmt = $conn->prepare('
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ');
    $stmt->execute([$table]);

    return $cache[$cacheKey] = ((int)$stmt->fetchColumn() > 0);
}

function apiMysqlOrderColumn(PDO $conn, string $table, string $preferred = 'created_at'): string
{
    return apiTableHasColumn($conn, $table, $preferred) ? $preferred : 'id';
}

function apiEnsureMpesaMessagesTable(PDO $conn): void
{
    static $ready = [];

    $cacheKey = spl_object_id($conn);
    if (isset($ready[$cacheKey])) {
        return;
    }

    $conn->exec('
        CREATE TABLE IF NOT EXISTS mpesa_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            marketer_id INT NOT NULL,
            message_text TEXT NOT NULL,
            transaction_id VARCHAR(100) DEFAULT NULL,
            amount DECIMAL(10,2) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'pending\',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mpesa_messages_marketer_id (marketer_id),
            CONSTRAINT fk_mpesa_messages_marketer
                FOREIGN KEY (marketer_id) REFERENCES marketers(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');

    $ready[$cacheKey] = true;
}

function apiEnsurePropertiesSchema(PDO $conn): void
{
    static $ready = [];

    $cacheKey = spl_object_id($conn);
    if (isset($ready[$cacheKey])) {
        return;
    }

    $requiredColumns = [
        'phone' => "ALTER TABLE properties ADD COLUMN phone VARCHAR(20) DEFAULT NULL AFTER owner_email",
        'phone_number_1' => "ALTER TABLE properties ADD COLUMN phone_number_1 VARCHAR(20) DEFAULT NULL AFTER phone",
        'phone_number_2' => "ALTER TABLE properties ADD COLUMN phone_number_2 VARCHAR(20) DEFAULT NULL AFTER phone_number_1",
        'whatsapp_phone' => "ALTER TABLE properties ADD COLUMN whatsapp_phone VARCHAR(20) DEFAULT NULL AFTER phone_number_2",
        'county' => "ALTER TABLE properties ADD COLUMN county VARCHAR(100) DEFAULT NULL AFTER property_location",
        'area' => "ALTER TABLE properties ADD COLUMN area VARCHAR(150) DEFAULT NULL AFTER county",
        'map_link' => "ALTER TABLE properties ADD COLUMN map_link TEXT DEFAULT NULL AFTER area",
        'booking_type' => "ALTER TABLE properties ADD COLUMN booking_type VARCHAR(50) DEFAULT NULL AFTER property_type",
        'package_selected' => "ALTER TABLE properties ADD COLUMN package_selected VARCHAR(100) DEFAULT NULL AFTER booking_type",
        'images_json' => "ALTER TABLE properties ADD COLUMN images_json LONGTEXT DEFAULT NULL",
        'payment_status' => "ALTER TABLE properties ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid'",
        'payment_amount' => "ALTER TABLE properties ADD COLUMN payment_amount DECIMAL(10,2) DEFAULT NULL",
        'payment_phone' => "ALTER TABLE properties ADD COLUMN payment_phone VARCHAR(20) DEFAULT NULL",
        'checkout_request_id' => "ALTER TABLE properties ADD COLUMN checkout_request_id VARCHAR(120) DEFAULT NULL",
        'merchant_request_id' => "ALTER TABLE properties ADD COLUMN merchant_request_id VARCHAR(120) DEFAULT NULL",
        'payment_reference' => "ALTER TABLE properties ADD COLUMN payment_reference VARCHAR(120) DEFAULT NULL",
        'mpesa_receipt_number' => "ALTER TABLE properties ADD COLUMN mpesa_receipt_number VARCHAR(120) DEFAULT NULL",
        'payment_result_desc' => "ALTER TABLE properties ADD COLUMN payment_result_desc TEXT DEFAULT NULL",
        'payment_requested_at' => "ALTER TABLE properties ADD COLUMN payment_requested_at DATETIME DEFAULT NULL",
        'paid_at' => "ALTER TABLE properties ADD COLUMN paid_at DATETIME DEFAULT NULL",
        'created_at' => "ALTER TABLE properties ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];

    foreach ($requiredColumns as $column => $sql) {
        if (apiTableHasColumn($conn, 'properties', $column)) {
            continue;
        }

        $conn->exec($sql);
        apiRefreshTableColumns($conn, 'properties');
    }

    $ready[$cacheKey] = true;
}

function apiEnsureAppSettingsTable(PDO $conn): void
{
    static $ready = [];

    $cacheKey = spl_object_id($conn);
    if (isset($ready[$cacheKey])) {
        return;
    }

    $conn->exec('
        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(191) PRIMARY KEY,
            setting_value LONGTEXT DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');

    $ready[$cacheKey] = true;
}

function apiGetAppSetting(PDO $conn, string $key, ?string $default = null): ?string
{
    apiEnsureAppSettingsTable($conn);

    $stmt = $conn->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    if ($value === false) {
        return $default;
    }

    return $value === null ? null : (string)$value;
}

function apiSetAppSetting(PDO $conn, string $key, ?string $value): void
{
    apiEnsureAppSettingsTable($conn);

    $stmt = $conn->prepare('
        INSERT INTO app_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([$key, $value]);
}

function apiValueToArray($value): array
{
    if (is_array($value)) {
        return $value;
    }

    if ($value instanceof Traversable) {
        return iterator_to_array($value);
    }

    if (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    if (is_object($value)) {
        $decoded = json_decode(json_encode($value), true);
        return is_array($decoded) ? $decoded : [];
    }

    return [];
}

function apiNormalizePropertyImages($value): array
{
    $images = apiValueToArray($value);

    $normalized = [];
    foreach ($images as $image) {
        if (is_string($image)) {
            $candidate = trim($image);
        } else {
            $image = apiValueToArray($image);
            $candidate = trim((string)($image['data_url'] ?? ($image['url'] ?? ($image['src'] ?? ''))));
        }

        if ($candidate === '') {
            continue;
        }

        $normalized[] = $candidate;
        if (count($normalized) >= 8) {
            break;
        }
    }

    return $normalized;
}

function apiPropertyPackageCatalog(): array
{
    return [
        'basic' => ['name' => 'Basic', 'amount' => 5000],
        'advanced' => ['name' => 'Advanced', 'amount' => 10000],
        'premium' => ['name' => 'Premium', 'amount' => 15000],
    ];
}

function apiResolvePackageAmount(string $packageName): ?float
{
    $normalized = strtolower(trim($packageName));
    if ($normalized === '') {
        return null;
    }

    $catalog = apiPropertyPackageCatalog();
    return isset($catalog[$normalized]) ? (float)$catalog[$normalized]['amount'] : null;
}

function apiResolvedPropertyPaymentAmount($property): ?float
{
    $property = apiValueToArray($property);

    $paymentAmount = isset($property['payment_amount']) ? (float)$property['payment_amount'] : null;
    if ($paymentAmount !== null && $paymentAmount > 0) {
        return $paymentAmount;
    }

    return apiResolvePackageAmount((string)($property['package_selected'] ?? ''));
}

function apiNormalizeKenyanPhone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits === '') {
        return '';
    }

    if (strpos($digits, '254') === 0 && strlen($digits) >= 12) {
        return substr($digits, 0, 12);
    }

    if (strpos($digits, '0') === 0 && strlen($digits) >= 10) {
        return '254' . substr($digits, 1, 9);
    }

    if (strpos($digits, '7') === 0 && strlen($digits) === 9) {
        return '254' . $digits;
    }

    return $digits;
}

function apiMpesaTimestampToMySql(?string $value): ?string
{
    $timestamp = trim((string)$value);
    if ($timestamp === '' || strlen($timestamp) !== 14) {
        return null;
    }

    $dt = DateTime::createFromFormat('YmdHis', $timestamp);
    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}

function apiResolveMySqlMarketerId(PDO $conn): int
{
    $marketerId = isset($_SERVER['HTTP_X_AUTH_MARKETER_ID']) ? (int)$_SERVER['HTTP_X_AUTH_MARKETER_ID'] : 0;
    if ($marketerId > 0) {
        return $marketerId;
    }

    if (isset($_SESSION['marketer_id'])) {
        return (int)$_SESSION['marketer_id'];
    }

    $username = trim((string)($_SERVER['HTTP_X_AUTH_USER'] ?? ($_SESSION['username'] ?? '')));
    if ($username === '') {
        return 0;
    }

    $stmt = $conn->prepare('SELECT id FROM marketers WHERE name = ? OR email = ? LIMIT 1');
    $stmt->execute([$username, $username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? (int)$row['id'] : 0;
}

function apiRoomCategoryDefaults(): array
{
    return [
        ['id' => 1, 'name' => 'Single Room', 'aliases' => ['single', 'single room']],
        ['id' => 2, 'name' => 'Bedsitter', 'aliases' => ['bedsitter', 'bed sitter']],
        ['id' => 3, 'name' => '1 Bedroom', 'aliases' => ['1 bedroom', 'one bedroom', '1-bedroom']],
        ['id' => 4, 'name' => 'Standard Lodge Room', 'aliases' => ['standard lodge room', 'standard room', 'lodge room']],
        ['id' => 5, 'name' => 'Executive Room', 'aliases' => ['executive room']],
        ['id' => 6, 'name' => 'Other', 'aliases' => ['other']],
    ];
}

function apiNormalizeRoomCategoryName(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
    return trim($value);
}

function apiRoomCategoryAliasMap(): array
{
    static $map = null;

    if ($map !== null) {
        return $map;
    }

    $map = [];
    foreach (apiRoomCategoryDefaults() as $category) {
        $canonical = apiNormalizeRoomCategoryName($category['name']);
        $map[$canonical] = $category['name'];

        foreach ($category['aliases'] as $alias) {
            $map[apiNormalizeRoomCategoryName($alias)] = $category['name'];
        }
    }

    return $map;
}

function apiEnsureRoomCategories(PDO $conn): array
{
    static $cache = [];

    $cacheKey = spl_object_id($conn);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    try {
        if (!apiTableHasColumn($conn, 'room_categories', 'name')) {
            return $cache[$cacheKey] = [];
        }

        $rows = $conn->query('SELECT id, name FROM room_categories ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            $insert = $conn->prepare('INSERT INTO room_categories (id, name) VALUES (?, ?)');
            foreach (apiRoomCategoryDefaults() as $category) {
                $insert->execute([(int)$category['id'], $category['name']]);
            }
            $rows = $conn->query('SELECT id, name FROM room_categories ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $existing = [];
            foreach ($rows as $row) {
                $existing[apiNormalizeRoomCategoryName((string)$row['name'])] = true;
            }

            $insert = null;
            foreach (apiRoomCategoryDefaults() as $category) {
                $normalized = apiNormalizeRoomCategoryName($category['name']);
                if (isset($existing[$normalized])) {
                    continue;
                }

                $insert ??= $conn->prepare('INSERT INTO room_categories (name) VALUES (?)');
                $insert->execute([$category['name']]);
            }

            if ($insert !== null) {
                $rows = $conn->query('SELECT id, name FROM room_categories ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        $categories = [];
        foreach ($rows as $row) {
            $categories[(int)$row['id']] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'normalized' => apiNormalizeRoomCategoryName((string)$row['name']),
            ];
        }

        return $cache[$cacheKey] = $categories;
    } catch (Throwable $e) {
        return $cache[$cacheKey] = [];
    }
}

function apiRoomTypeFromCategory(PDO $conn, ?int $categoryId): string
{
    $categories = apiEnsureRoomCategories($conn);
    if ($categoryId !== null && isset($categories[$categoryId])) {
        return $categories[$categoryId]['name'];
    }

    foreach (apiRoomCategoryDefaults() as $category) {
        if ((int)$category['id'] === (int)($categoryId ?? 0)) {
            return $category['name'];
        }
    }

    return 'Room';
}

function apiRoomCategoryFromType(PDO $conn, string $roomType): int
{
    $normalized = apiNormalizeRoomCategoryName($roomType);
    $canonical = apiRoomCategoryAliasMap()[$normalized] ?? null;
    $target = apiNormalizeRoomCategoryName($canonical ?? $roomType);

    foreach (apiEnsureRoomCategories($conn) as $category) {
        if ($category['normalized'] === $target) {
            return (int)$category['id'];
        }
    }

    foreach (apiRoomCategoryDefaults() as $category) {
        if (apiNormalizeRoomCategoryName($category['name']) === $target) {
            return (int)$category['id'];
        }
    }

    return 6;
}

function apiFetchMySqlRooms(PDO $conn, int $propertyId): array
{
    if ($propertyId <= 0) {
        return [];
    }

    if (apiTableHasColumn($conn, 'property_rooms', 'room_type')) {
        $stmt = $conn->prepare('
            SELECT room_type, COALESCE(room_size, "") AS room_size, price, availability
            FROM property_rooms
            WHERE property_id = ?
            ORDER BY id ASC
        ');
        $stmt->execute([$propertyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!apiTableHasColumn($conn, 'property_rooms', 'category_id')) {
        return [];
    }

    $stmt = $conn->prepare('
        SELECT category_id, price, availability
        FROM property_rooms
        WHERE property_id = ?
        ORDER BY id ASC
    ');
    $stmt->execute([$propertyId]);

    return array_map(
        static function (array $row) use ($conn): array {
            return [
                'room_type' => apiRoomTypeFromCategory($conn, isset($row['category_id']) ? (int)$row['category_id'] : null),
                'room_size' => '',
                'price' => isset($row['price']) ? (float)$row['price'] : 0,
                'availability' => isset($row['availability']) ? (int)$row['availability'] : 0,
            ];
        },
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );
}

function apiBuildMySqlPropertyRow(PDO $conn, array $row): array
{
    $propertyId = (int)($row['id'] ?? 0);
    $primaryPhone = (string)($row['phone_number_1'] ?? ($row['phone'] ?? ($row['phone_number'] ?? '')));

    return [
        'id' => $propertyId,
        'marketer_id' => (int)($row['marketer_id'] ?? 0),
        'owner_name' => (string)($row['owner_name'] ?? ''),
        'owner_email' => (string)($row['owner_email'] ?? ''),
        'phone' => $primaryPhone,
        'phone_number' => $primaryPhone,
        'phone_number_1' => $primaryPhone,
        'phone_number_2' => (string)($row['phone_number_2'] ?? ''),
        'whatsapp_phone' => (string)($row['whatsapp_phone'] ?? ''),
        'property_name' => (string)($row['property_name'] ?? ''),
        'property_location' => (string)($row['property_location'] ?? ''),
        'property_type' => (string)($row['property_type'] ?? ''),
        'booking_type' => (string)($row['booking_type'] ?? ''),
        'package_selected' => (string)($row['package_selected'] ?? ''),
        'county' => (string)($row['county'] ?? ''),
        'area' => (string)($row['area'] ?? ''),
        'map_link' => (string)($row['map_link'] ?? ''),
        'status' => (string)($row['status'] ?? 'pending'),
        'payment_status' => (string)($row['payment_status'] ?? 'unpaid'),
        'payment_amount' => apiResolvedPropertyPaymentAmount($row),
        'payment_phone' => (string)($row['payment_phone'] ?? ''),
        'checkout_request_id' => (string)($row['checkout_request_id'] ?? ''),
        'merchant_request_id' => (string)($row['merchant_request_id'] ?? ''),
        'payment_reference' => (string)($row['payment_reference'] ?? ''),
        'mpesa_receipt_number' => (string)($row['mpesa_receipt_number'] ?? ''),
        'payment_result_desc' => (string)($row['payment_result_desc'] ?? ''),
        'payment_requested_at' => isset($row['payment_requested_at']) ? (string)$row['payment_requested_at'] : null,
        'paid_at' => isset($row['paid_at']) ? (string)$row['paid_at'] : null,
        'images' => apiNormalizePropertyImages($row['images_json'] ?? null),
        'rooms' => apiFetchMySqlRooms($conn, $propertyId),
        'created_at' => isset($row['created_at']) ? (string)$row['created_at'] : null,
        'marketer_name' => (string)($row['marketer_name'] ?? ''),
        'marketer_phone' => (string)($row['marketer_phone'] ?? ''),
    ];
}

function apiMySqlCurrentUser(PDO $conn): ?array
{
    apiEnsureSessionStarted();

    if (!isset($_SESSION['user_id'], $_SESSION['user_type'])) {
        return null;
    }

    $userId = (int)$_SESSION['user_id'];
    $userType = (string)$_SESSION['user_type'];

    if ($userType === 'admin') {
        $stmt = $conn->prepare('SELECT id, username, full_name, email FROM admins WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin) {
            return null;
        }

        return [
            'user_id' => (int)$admin['id'],
            'id' => (int)$admin['id'],
            'username' => (string)$admin['username'],
            'name' => (string)($admin['full_name'] ?: $admin['username']),
            'full_name' => (string)($admin['full_name'] ?: $admin['username']),
            'email' => (string)($admin['email'] ?? ''),
            'user_type' => 'admin',
        ];
    }

    if ($userType === 'marketer') {
        $marketerId = isset($_SESSION['marketer_id']) ? (int)$_SESSION['marketer_id'] : $userId;
        $stmt = $conn->prepare('
            SELECT id, name, email, phone, is_authorized, is_blocked, must_change_password
            FROM marketers
            WHERE id = ?
            LIMIT 1
        ');
        $stmt->execute([$marketerId]);
        $marketer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$marketer) {
            return null;
        }

        return [
            'user_id' => (int)$marketer['id'],
            'id' => (int)$marketer['id'],
            'username' => (string)$marketer['name'],
            'name' => (string)$marketer['name'],
            'email' => (string)($marketer['email'] ?? ''),
            'phone' => (string)($marketer['phone'] ?? ''),
            'user_type' => 'marketer',
            'marketer_id' => (int)$marketer['id'],
            'is_authorized' => isset($marketer['is_authorized']) ? (int)$marketer['is_authorized'] : 0,
            'is_blocked' => isset($marketer['is_blocked']) ? (int)$marketer['is_blocked'] : 0,
            'must_change_password' => isset($marketer['must_change_password']) ? (int)$marketer['must_change_password'] : 0,
        ];
    }

    return null;
}
