<?php
/**
 * Marketer Submit Property API (MongoDB)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../mongo/config.php';
ensureSession();

$response = ['success' => false, 'message' => ''];

function nextPropertyId($collection)
{
    $last = $collection->findOne([], ['sort' => ['_id' => -1], 'projection' => ['_id' => 1]]);
    return $last ? (normalizeId($last['_id']) + 1) : 1;
}

try {
    $db = mongoDb();

    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        $response['message'] = 'Invalid request data';
        echo json_encode($response);
        exit;
    }

    $marketerId = isset($_SERVER['HTTP_X_AUTH_MARKETER_ID']) ? (int)$_SERVER['HTTP_X_AUTH_MARKETER_ID'] : 0;
    if ($marketerId <= 0 && isset($_SESSION['marketer_id'])) {
        $marketerId = normalizeId($_SESSION['marketer_id']);
    }
    if ($marketerId <= 0) {
        $username = $_SERVER['HTTP_X_AUTH_USER'] ?? '';
        if ($username !== '') {
            $mk = $db->marketers->findOne(['$or' => [['name' => $username], ['email' => $username]]], ['projection' => ['_id' => 1]]);
            if ($mk) {
                $marketerId = normalizeId($mk['_id']);
            }
        }
    }

    if ($marketerId <= 0) {
        $response['message'] = 'Authentication required';
        echo json_encode($response);
        exit;
    }

    $ownerName = trim((string)($data['owner_name'] ?? ''));
    $ownerEmail = trim((string)($data['owner_email'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ($data['phone_number'] ?? '')));
    $propertyName = trim((string)($data['property_name'] ?? ''));
    $county = trim((string)($data['county'] ?? ''));
    $area = trim((string)($data['area'] ?? ''));
    $propertyType = is_array($data['property_type'] ?? null)
        ? implode(', ', array_filter(array_map('trim', $data['property_type'])))
        : trim((string)($data['property_type'] ?? ''));
    $bookingType = trim((string)($data['booking_type'] ?? ''));
    $packageSelected = trim((string)($data['package_selected'] ?? ''));
    $propertyLocation = $area !== '' ? $area : trim((string)($data['property_location'] ?? ''));

    if ($ownerName === '' || $propertyName === '' || $county === '' || $area === '' || $propertyType === '' || $bookingType === '' || $packageSelected === '') {
        $response['message'] = 'Missing required property fields';
        echo json_encode($response);
        exit;
    }

    $roomsDocs = [];
    if (isset($data['rooms']) && is_array($data['rooms'])) {
        foreach ($data['rooms'] as $room) {
            $roomType = trim((string)($room['room_type'] ?? ''));
            $roomSize = trim((string)($room['room_size'] ?? ''));
            $price = isset($room['price']) ? (float)$room['price'] : 0;
            $availability = trim((string)($room['availability'] ?? ''));
            if ($roomType !== '') {
                $roomsDocs[] = [
                    'room_type' => $roomType,
                    'room_size' => $roomSize,
                    'price' => $price,
                    'availability' => $availability,
                ];
            }
        }
    }

    $collection = $db->properties;
    $newId = nextPropertyId($collection);

    $collection->insertOne([
        '_id' => $newId,
        'marketer_id' => $marketerId,
        'owner_name' => $ownerName,
        'owner_email' => $ownerEmail,
        'phone' => $phone,
        'property_name' => $propertyName,
        'property_location' => $propertyLocation,
        'property_type' => $propertyType,
        'booking_type' => $bookingType,
        'package_selected' => $packageSelected,
        'county' => $county,
        'area' => $area,
        'status' => 'pending',
        'rooms' => $roomsDocs,
        'created_at' => mongoNow(),
    ]);

    // New submissions should become visible to marketers again
    $db->app_settings->updateOne(
        ['_id' => 'marketer_properties_hidden'],
        ['$set' => ['value' => 0, 'updated_at' => mongoNow()]],
        ['upsert' => true]
    );

    $response['success'] = true;
    $response['message'] = 'Property submitted successfully!';
    $response['data'] = ['property_id' => $newId];
} catch (Throwable $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log('Submit Property Error: ' . $e->getMessage());
}

echo json_encode($response);
