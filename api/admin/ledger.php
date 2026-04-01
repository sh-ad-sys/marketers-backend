<?php
/**
 * Admin Ledger API
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../shared/storage.php';

$response = ['success' => false, 'message' => '', 'data' => []];

function ledgerCycleSettingKey(): string
{
    return 'ledger_cycle_started_at';
}

function ledgerDefaultCycleStart(): string
{
    $now = new DateTimeImmutable('now');
    $start = $now->setTime(0, 0, 0);
    $dayOfWeek = (int)$start->format('N');

    if ($dayOfWeek > 1) {
        $start = $start->modify('-' . ($dayOfWeek - 1) . ' days');
    }

    return $start->format('Y-m-d H:i:s');
}

function ledgerParseDateValue($value): ?DateTimeImmutable
{
    if ($value instanceof DateTimeInterface) {
        return DateTimeImmutable::createFromInterface($value);
    }

    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    try {
        return new DateTimeImmutable(str_replace(' ', 'T', $raw));
    } catch (Throwable $e) {
        return null;
    }
}

function ledgerPriorityMeta(string $packageSelected): array
{
    $normalized = strtolower(trim($packageSelected));

    if ($normalized === 'premium') {
        return [
            'package_name' => 'Premium',
            'priority_key' => 'top_priority',
            'priority_label' => 'Top Priority',
            'priority_score' => 3,
            'package_amount' => apiResolvePackageAmount('premium'),
        ];
    }

    if ($normalized === 'advanced') {
        return [
            'package_name' => 'Advanced',
            'priority_key' => 'priority',
            'priority_label' => 'Priority',
            'priority_score' => 2,
            'package_amount' => apiResolvePackageAmount('advanced'),
        ];
    }

    return [
        'package_name' => $normalized === 'basic' ? 'Basic' : (trim($packageSelected) !== '' ? trim($packageSelected) : 'Unassigned'),
        'priority_key' => 'standard',
        'priority_label' => 'Standard',
        'priority_score' => 1,
        'package_amount' => apiResolvePackageAmount('basic'),
    ];
}

function ledgerBuildPayload(array $marketers, array $properties, string $cycleStartedAt): array
{
    $cycleStart = ledgerParseDateValue($cycleStartedAt) ?? ledgerParseDateValue(ledgerDefaultCycleStart());
    $cycleStartString = $cycleStart ? $cycleStart->format('Y-m-d H:i:s') : ledgerDefaultCycleStart();

    $ledgerRows = [];
    foreach ($marketers as $marketer) {
        $id = (int)($marketer['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $ledgerRows[$id] = [
            'id' => $id,
            'name' => (string)($marketer['name'] ?? ''),
            'email' => (string)($marketer['email'] ?? ''),
            'phone' => (string)($marketer['phone'] ?? ''),
            'is_authorized' => isset($marketer['is_authorized']) ? (int)$marketer['is_authorized'] : 0,
            'is_blocked' => isset($marketer['is_blocked']) ? (int)$marketer['is_blocked'] : 0,
            'total_properties_count' => 0,
            'total_priority_score' => 0,
            'total_package_value_total' => 0.0,
            'total_paid_properties_count' => 0,
            'total_unpaid_properties_count' => 0,
            'cycle_properties_count' => 0,
            'cycle_priority_score' => 0,
            'cycle_package_value_total' => 0.0,
            'cycle_paid_properties_count' => 0,
            'cycle_unpaid_properties_count' => 0,
            'latest_property_at' => null,
            'latest_cycle_property_at' => null,
            'priority_mix' => [
                'standard' => 0,
                'priority' => 0,
                'top_priority' => 0,
            ],
            'cycle_priority_mix' => [
                'standard' => 0,
                'priority' => 0,
                'top_priority' => 0,
            ],
            'properties' => [],
            'cycle_properties' => [],
        ];
    }

    foreach ($properties as $property) {
        $marketerId = (int)($property['marketer_id'] ?? 0);
        if ($marketerId <= 0) {
            continue;
        }

        if (!isset($ledgerRows[$marketerId])) {
            $ledgerRows[$marketerId] = [
                'id' => $marketerId,
                'name' => (string)($property['marketer_name'] ?? 'Unknown'),
                'email' => '',
                'phone' => (string)($property['marketer_phone'] ?? ''),
                'is_authorized' => 0,
                'is_blocked' => 0,
                'total_properties_count' => 0,
                'total_priority_score' => 0,
                'total_package_value_total' => 0.0,
                'total_paid_properties_count' => 0,
                'total_unpaid_properties_count' => 0,
                'cycle_properties_count' => 0,
                'cycle_priority_score' => 0,
                'cycle_package_value_total' => 0.0,
                'cycle_paid_properties_count' => 0,
                'cycle_unpaid_properties_count' => 0,
                'latest_property_at' => null,
                'latest_cycle_property_at' => null,
                'priority_mix' => [
                    'standard' => 0,
                    'priority' => 0,
                    'top_priority' => 0,
                ],
                'cycle_priority_mix' => [
                    'standard' => 0,
                    'priority' => 0,
                    'top_priority' => 0,
                ],
                'properties' => [],
                'cycle_properties' => [],
            ];
        }

        $createdAt = ledgerParseDateValue($property['created_at'] ?? null);
        $createdAtString = $createdAt ? $createdAt->format('Y-m-d H:i:s') : null;
        if (
            $createdAtString !== null
            && (
                $ledgerRows[$marketerId]['latest_property_at'] === null
                || strcmp($createdAtString, (string)$ledgerRows[$marketerId]['latest_property_at']) > 0
            )
        ) {
            $ledgerRows[$marketerId]['latest_property_at'] = $createdAtString;
        }

        $priorityMeta = ledgerPriorityMeta((string)($property['package_selected'] ?? ''));
        $packageAmount = isset($property['payment_amount']) && $property['payment_amount'] !== null
            ? (float)$property['payment_amount']
            : (isset($priorityMeta['package_amount']) ? (float)$priorityMeta['package_amount'] : 0.0);
        $paymentStatus = strtolower(trim((string)($property['payment_status'] ?? 'unpaid')));
        $isCycleProperty = $createdAt && (!$cycleStart || $createdAt >= $cycleStart);

        $propertyRow = [
            'id' => (int)($property['id'] ?? 0),
            'property_name' => (string)($property['property_name'] ?? ''),
            'property_location' => (string)($property['property_location'] ?? ''),
            'county' => (string)($property['county'] ?? ''),
            'area' => (string)($property['area'] ?? ''),
            'status' => (string)($property['status'] ?? 'pending'),
            'payment_status' => (string)($property['payment_status'] ?? 'unpaid'),
            'package_selected' => (string)($property['package_selected'] ?? ''),
            'package_amount' => $packageAmount,
            'priority_key' => (string)$priorityMeta['priority_key'],
            'priority_label' => (string)$priorityMeta['priority_label'],
            'created_at' => $createdAtString,
            'is_cycle_property' => (bool)$isCycleProperty,
            'cycle_scope_label' => $isCycleProperty ? 'This Week Payment' : 'History',
        ];

        $ledgerRows[$marketerId]['total_properties_count'] += 1;
        $ledgerRows[$marketerId]['total_priority_score'] += (int)$priorityMeta['priority_score'];
        $ledgerRows[$marketerId]['total_package_value_total'] += $packageAmount;
        $ledgerRows[$marketerId]['priority_mix'][$priorityMeta['priority_key']] += 1;

        if ($paymentStatus === 'completed') {
            $ledgerRows[$marketerId]['total_paid_properties_count'] += 1;
        } else {
            $ledgerRows[$marketerId]['total_unpaid_properties_count'] += 1;
        }

        $ledgerRows[$marketerId]['properties'][] = $propertyRow;

        if (!$isCycleProperty) {
            continue;
        }

        $ledgerRows[$marketerId]['cycle_properties_count'] += 1;
        $ledgerRows[$marketerId]['cycle_priority_score'] += (int)$priorityMeta['priority_score'];
        $ledgerRows[$marketerId]['cycle_priority_mix'][$priorityMeta['priority_key']] += 1;
        $ledgerRows[$marketerId]['cycle_package_value_total'] += $packageAmount;

        if ($paymentStatus === 'completed') {
            $ledgerRows[$marketerId]['cycle_paid_properties_count'] += 1;
        } else {
            $ledgerRows[$marketerId]['cycle_unpaid_properties_count'] += 1;
        }

        if (
            $ledgerRows[$marketerId]['latest_cycle_property_at'] === null
            || strcmp($createdAtString, (string)$ledgerRows[$marketerId]['latest_cycle_property_at']) > 0
        ) {
            $ledgerRows[$marketerId]['latest_cycle_property_at'] = $createdAtString;
        }

        $ledgerRows[$marketerId]['cycle_properties'][] = $propertyRow;
    }

    $summary = [
        'marketers_total' => count($ledgerRows),
        'marketers_with_cycle_properties' => 0,
        'properties_total' => count($properties),
        'total_priority_score_total' => 0,
        'total_package_value_total' => 0.0,
        'total_paid_properties_total' => 0,
        'total_unpaid_properties_total' => 0,
        'cycle_properties_total' => 0,
        'cycle_priority_score_total' => 0,
        'cycle_package_value_total' => 0.0,
        'all_priority_totals' => [
            'standard' => 0,
            'priority' => 0,
            'top_priority' => 0,
        ],
        'priority_totals' => [
            'standard' => 0,
            'priority' => 0,
            'top_priority' => 0,
        ],
    ];

    $rows = array_values($ledgerRows);
    foreach ($rows as &$row) {
        if (!empty($row['properties'])) {
            usort(
                $row['properties'],
                static function (array $a, array $b): int {
                    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
                }
            );
        }

        if (!empty($row['cycle_properties'])) {
            usort(
                $row['cycle_properties'],
                static function (array $a, array $b): int {
                    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
                }
            );
        }

        $row['total_package_value_total'] = round((float)$row['total_package_value_total'], 2);
        $row['cycle_package_value_total'] = round((float)$row['cycle_package_value_total'], 2);

        if ($row['cycle_properties_count'] > 0) {
            $summary['marketers_with_cycle_properties'] += 1;
        }

        $summary['total_priority_score_total'] += (int)$row['total_priority_score'];
        $summary['total_package_value_total'] += (float)$row['total_package_value_total'];
        $summary['total_paid_properties_total'] += (int)$row['total_paid_properties_count'];
        $summary['total_unpaid_properties_total'] += (int)$row['total_unpaid_properties_count'];
        $summary['cycle_properties_total'] += (int)$row['cycle_properties_count'];
        $summary['cycle_priority_score_total'] += (int)$row['cycle_priority_score'];
        $summary['cycle_package_value_total'] += (float)$row['cycle_package_value_total'];
        $summary['all_priority_totals']['standard'] += (int)$row['priority_mix']['standard'];
        $summary['all_priority_totals']['priority'] += (int)$row['priority_mix']['priority'];
        $summary['all_priority_totals']['top_priority'] += (int)$row['priority_mix']['top_priority'];
        $summary['priority_totals']['standard'] += (int)$row['cycle_priority_mix']['standard'];
        $summary['priority_totals']['priority'] += (int)$row['cycle_priority_mix']['priority'];
        $summary['priority_totals']['top_priority'] += (int)$row['cycle_priority_mix']['top_priority'];
    }
    unset($row);

    usort(
        $rows,
        static function (array $a, array $b): int {
            if ($a['cycle_properties_count'] !== $b['cycle_properties_count']) {
                return $b['cycle_properties_count'] <=> $a['cycle_properties_count'];
            }

            if ($a['total_properties_count'] !== $b['total_properties_count']) {
                return $b['total_properties_count'] <=> $a['total_properties_count'];
            }

            if ($a['cycle_priority_score'] !== $b['cycle_priority_score']) {
                return $b['cycle_priority_score'] <=> $a['cycle_priority_score'];
            }

            $latestCompare = strcmp((string)($b['latest_property_at'] ?? ''), (string)($a['latest_property_at'] ?? ''));
            if ($latestCompare !== 0) {
                return $latestCompare;
            }

            return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        }
    );

    $summary['total_package_value_total'] = round((float)$summary['total_package_value_total'], 2);
    $summary['cycle_package_value_total'] = round((float)$summary['cycle_package_value_total'], 2);

    return [
        'cycle_started_at' => $cycleStartString,
        'generated_at' => date('Y-m-d H:i:s'),
        'summary' => $summary,
        'marketers' => $rows,
    ];
}

try {
    if (!isAdminLoggedIn()) {
        $response['message'] = 'Admin access required';
        echo json_encode($response);
        exit;
    }

    if (apiUsesMongoStorage()) {
        require_once __DIR__ . '/../mongo/config.php';

        $db = mongoDb();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = apiJsonInput() ?: $_REQUEST;
            $action = trim((string)($data['action'] ?? ''));

            if ($action !== 'refresh_weekly') {
                $response['message'] = 'Invalid action';
                echo json_encode($response);
                exit;
            }

            $cycleStartedAt = date('Y-m-d H:i:s');
            $db->app_settings->updateOne(
                ['_id' => ledgerCycleSettingKey()],
                ['$set' => ['value' => $cycleStartedAt, 'updated_at' => mongoNow()]],
                ['upsert' => true]
            );

            echo json_encode([
                'success' => true,
                'message' => 'Ledger cycle refreshed successfully',
                'data' => ['cycle_started_at' => $cycleStartedAt],
            ]);
            exit;
        }

        $setting = $db->app_settings->findOne(['_id' => ledgerCycleSettingKey()]);
        $cycleStartedAt = trim((string)($setting['value'] ?? ''));
        if ($cycleStartedAt === '') {
            $cycleStartedAt = ledgerDefaultCycleStart();
        }

        $marketers = [];
        foreach ($db->marketers->find([], ['sort' => ['created_at' => -1, '_id' => -1]]) as $doc) {
            $marketers[] = [
                'id' => normalizeId($doc['_id'] ?? 0),
                'name' => (string)($doc['name'] ?? ''),
                'phone' => (string)($doc['phone'] ?? ''),
                'email' => (string)($doc['email'] ?? ''),
                'is_authorized' => isset($doc['is_authorized']) ? (int)$doc['is_authorized'] : 0,
                'is_blocked' => isset($doc['is_blocked']) ? (int)$doc['is_blocked'] : 0,
            ];
        }

        $properties = [];
        foreach ($db->properties->find([], ['sort' => ['created_at' => -1, '_id' => -1]]) as $doc) {
            $properties[] = [
                'id' => normalizeId($doc['_id'] ?? 0),
                'marketer_id' => normalizeId($doc['marketer_id'] ?? 0),
                'property_name' => (string)($doc['property_name'] ?? ''),
                'property_location' => (string)($doc['property_location'] ?? ''),
                'county' => (string)($doc['county'] ?? ''),
                'area' => (string)($doc['area'] ?? ''),
                'package_selected' => (string)($doc['package_selected'] ?? ''),
                'status' => (string)($doc['status'] ?? 'pending'),
                'payment_status' => (string)($doc['payment_status'] ?? 'unpaid'),
                'payment_amount' => isset($doc['payment_amount']) ? (float)$doc['payment_amount'] : null,
                'created_at' => mongoDateToString($doc['created_at'] ?? null),
            ];
        }

        $response['success'] = true;
        $response['data'] = ledgerBuildPayload($marketers, $properties, $cycleStartedAt);
        echo json_encode($response);
        exit;
    }

    $conn = apiMysql();
    apiEnsurePropertiesSchema($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = apiJsonInput() ?: $_REQUEST;
        $action = trim((string)($data['action'] ?? ''));

        if ($action !== 'refresh_weekly') {
            $response['message'] = 'Invalid action';
            echo json_encode($response);
            exit;
        }

        $cycleStartedAt = date('Y-m-d H:i:s');
        apiSetAppSetting($conn, ledgerCycleSettingKey(), $cycleStartedAt);

        echo json_encode([
            'success' => true,
            'message' => 'Ledger cycle refreshed successfully',
            'data' => ['cycle_started_at' => $cycleStartedAt],
        ]);
        exit;
    }

    $cycleStartedAt = apiGetAppSetting($conn, ledgerCycleSettingKey(), ledgerDefaultCycleStart()) ?: ledgerDefaultCycleStart();
    $marketerOrderColumn = apiMysqlOrderColumn($conn, 'marketers');
    $propertyOrderColumn = apiMysqlOrderColumn($conn, 'properties');
    $marketerCreatedAtColumn = apiTableHasColumn($conn, 'marketers', 'created_at') ? 'created_at' : 'id';
    $marketerEmailSelect = apiTableHasColumn($conn, 'marketers', 'email') ? "COALESCE(email, '') AS email" : "'' AS email";
    $marketerAuthorizedSelect = apiTableHasColumn($conn, 'marketers', 'is_authorized') ? 'COALESCE(is_authorized, 0) AS is_authorized' : '0 AS is_authorized';
    $marketerBlockedSelect = apiTableHasColumn($conn, 'marketers', 'is_blocked') ? 'COALESCE(is_blocked, 0) AS is_blocked' : '0 AS is_blocked';
    $propertyNameSelect = "COALESCE(property_name, '') AS property_name";
    $propertyLocationSelect = apiTableHasColumn($conn, 'properties', 'property_location') ? "COALESCE(property_location, '') AS property_location" : "'' AS property_location";
    $propertyCountySelect = apiTableHasColumn($conn, 'properties', 'county') ? "COALESCE(county, '') AS county" : "'' AS county";
    $propertyAreaSelect = apiTableHasColumn($conn, 'properties', 'area') ? "COALESCE(area, '') AS area" : "'' AS area";
    $propertyPackageSelect = apiTableHasColumn($conn, 'properties', 'package_selected') ? "COALESCE(package_selected, '') AS package_selected" : "'' AS package_selected";
    $propertyStatusSelect = apiTableHasColumn($conn, 'properties', 'status') ? "COALESCE(status, 'pending') AS status" : "'pending' AS status";
    $propertyPaymentStatusSelect = apiTableHasColumn($conn, 'properties', 'payment_status') ? "COALESCE(payment_status, 'unpaid') AS payment_status" : "'unpaid' AS payment_status";
    $propertyPaymentAmountSelect = apiTableHasColumn($conn, 'properties', 'payment_amount') ? 'payment_amount' : 'NULL AS payment_amount';
    $propertyCreatedAtSelect = apiTableHasColumn($conn, 'properties', 'created_at') ? 'created_at' : 'NULL AS created_at';

    $marketers = $conn->query('
        SELECT id, name, phone, ' . $marketerEmailSelect . ',
               ' . $marketerAuthorizedSelect . ',
               ' . $marketerBlockedSelect . ',
               ' . $marketerCreatedAtColumn . ' AS sort_value
        FROM marketers
        ORDER BY ' . $marketerOrderColumn . ' DESC, id DESC
    ')->fetchAll(PDO::FETCH_ASSOC);

    $properties = $conn->query('
        SELECT
            id,
            marketer_id,
            ' . $propertyNameSelect . ',
            ' . $propertyLocationSelect . ',
            ' . $propertyCountySelect . ',
            ' . $propertyAreaSelect . ',
            ' . $propertyPackageSelect . ',
            ' . $propertyStatusSelect . ',
            ' . $propertyPaymentStatusSelect . ',
            ' . $propertyPaymentAmountSelect . ',
            ' . $propertyCreatedAtSelect . '
        FROM properties
        ORDER BY ' . $propertyOrderColumn . ' DESC, id DESC
    ')->fetchAll(PDO::FETCH_ASSOC);

    $response['success'] = true;
    $response['data'] = ledgerBuildPayload($marketers, $properties, $cycleStartedAt);
} catch (Throwable $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log('Ledger API Error: ' . $e->getMessage());
}

echo json_encode($response);
