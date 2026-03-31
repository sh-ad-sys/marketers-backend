<?php
/**
 * Marketer Submit Property API
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../shared/storage.php';
apiEnsureSessionStarted();

$response = ['success' => false, 'message' => ''];
$conn = null;

try {
    $data = apiJsonInput();
    if (!$data) {
        $response['message'] = 'Invalid request data';
        echo json_encode($response);
        exit;
    }

    $ownerName = trim((string)($data['owner_name'] ?? ''));
    $ownerEmail = trim((string)($data['owner_email'] ?? ''));
    $phoneNumber1 = trim((string)($data['phone_number_1'] ?? ($data['phone'] ?? ($data['phone_number'] ?? ''))));
    $phoneNumber2 = trim((string)($data['phone_number_2'] ?? ''));
    $whatsappPhone = trim((string)($data['whatsapp_phone'] ?? ''));
    $propertyName = trim((string)($data['property_name'] ?? ''));
    $county = trim((string)($data['county'] ?? ''));
    $area = trim((string)($data['area'] ?? ''));
    $mapLink = trim((string)($data['map_link'] ?? ''));
    $propertyType = is_array($data['property_type'] ?? null)
        ? implode(', ', array_filter(array_map('trim', $data['property_type'])))
        : trim((string)($data['property_type'] ?? ''));
    $bookingType = trim((string)($data['booking_type'] ?? ''));
    $packageSelected = trim((string)($data['package_selected'] ?? ''));
    $propertyLocation = $area !== '' ? $area : trim((string)($data['property_location'] ?? ''));
    $submittedImages = $data['images'] ?? [];
    if (is_array($submittedImages) && count($submittedImages) > 8) {
        $response['message'] = 'You can upload a maximum of 8 images';
        echo json_encode($response);
        exit;
    }
    $images = apiNormalizePropertyImages($submittedImages);

    if ($ownerName === '' || $phoneNumber1 === '' || $whatsappPhone === '' || $propertyName === '' || $county === '' || $area === '' || $propertyType === '' || $bookingType === '' || $packageSelected === '') {
        $response['message'] = 'Missing required property fields';
        echo json_encode($response);
        exit;
    }

    $packageAmount = apiResolvePackageAmount($packageSelected);
    if ($packageAmount === null) {
        $response['message'] = 'Invalid package selected';
        echo json_encode($response);
        exit;
    }

    if (apiUsesMongoStorage()) {
        require_once __DIR__ . '/../mongo/config.php';
        ensureSession();

        $db = mongoDb();
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

        $roomsDocs = [];
        if (isset($data['rooms']) && is_array($data['rooms'])) {
            foreach ($data['rooms'] as $room) {
                $roomType = trim((string)($room['room_type'] ?? ''));
                if ($roomType === '') {
                    continue;
                }

                $roomsDocs[] = [
                    'room_type' => $roomType,
                    'room_size' => trim((string)($room['room_size'] ?? '')),
                    'price' => isset($room['price']) ? (float)$room['price'] : 0,
                    'availability' => trim((string)($room['availability'] ?? '')),
                ];
            }
        }

        $collection = $db->properties;
        $newId = (int)(($collection->findOne([], ['sort' => ['_id' => -1], 'projection' => ['_id' => 1]])['_id'] ?? 0)) + 1;

        $collection->insertOne([
            '_id' => $newId,
            'marketer_id' => $marketerId,
            'owner_name' => $ownerName,
            'owner_email' => $ownerEmail,
            'phone' => $phoneNumber1,
            'phone_number' => $phoneNumber1,
            'phone_number_1' => $phoneNumber1,
            'phone_number_2' => $phoneNumber2,
            'whatsapp_phone' => $whatsappPhone,
            'property_name' => $propertyName,
            'property_location' => $propertyLocation,
            'property_type' => $propertyType,
            'booking_type' => $bookingType,
            'package_selected' => $packageSelected,
            'county' => $county,
            'area' => $area,
            'map_link' => $mapLink,
            'images' => $images,
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'payment_amount' => $packageAmount,
            'payment_phone' => '',
            'checkout_request_id' => '',
            'merchant_request_id' => '',
            'payment_reference' => '',
            'mpesa_receipt_number' => '',
            'payment_result_desc' => '',
            'payment_requested_at' => null,
            'paid_at' => null,
            'rooms' => $roomsDocs,
            'created_at' => mongoNow(),
        ]);

        $response['success'] = true;
        $response['message'] = 'Property submitted successfully!';
        $response['data'] = ['property_id' => $newId];
        echo json_encode($response);
        exit;
    }

    $conn = apiMysql();
    apiEnsurePropertiesSchema($conn);
    $conn->beginTransaction();
    $marketerId = apiResolveMySqlMarketerId($conn);
    if ($marketerId <= 0) {
        $conn->rollBack();
        $response['message'] = 'Authentication required';
        echo json_encode($response);
        exit;
    }

    $fields = ['marketer_id', 'owner_name', 'owner_email', 'property_name', 'property_location', 'property_type', 'status'];
    $values = [$marketerId, $ownerName, $ownerEmail, $propertyName, $propertyLocation, $propertyType, 'pending'];

    if (apiTableHasColumn($conn, 'properties', 'phone')) {
        $fields[] = 'phone';
        $values[] = $phoneNumber1;
    }
    if (apiTableHasColumn($conn, 'properties', 'phone_number')) {
        $fields[] = 'phone_number';
        $values[] = $phoneNumber1;
    }
    if (apiTableHasColumn($conn, 'properties', 'phone_number_1')) {
        $fields[] = 'phone_number_1';
        $values[] = $phoneNumber1;
    }
    if (apiTableHasColumn($conn, 'properties', 'phone_number_2')) {
        $fields[] = 'phone_number_2';
        $values[] = $phoneNumber2;
    }
    if (apiTableHasColumn($conn, 'properties', 'whatsapp_phone')) {
        $fields[] = 'whatsapp_phone';
        $values[] = $whatsappPhone;
    }

    if (apiTableHasColumn($conn, 'properties', 'county')) {
        $fields[] = 'county';
        $values[] = $county;
    }
    if (apiTableHasColumn($conn, 'properties', 'area')) {
        $fields[] = 'area';
        $values[] = $area;
    }
    if (apiTableHasColumn($conn, 'properties', 'map_link')) {
        $fields[] = 'map_link';
        $values[] = $mapLink;
    }
    if (apiTableHasColumn($conn, 'properties', 'booking_type')) {
        $fields[] = 'booking_type';
        $values[] = $bookingType;
    }
    if (apiTableHasColumn($conn, 'properties', 'package_selected')) {
        $fields[] = 'package_selected';
        $values[] = $packageSelected;
    }
    if (apiTableHasColumn($conn, 'properties', 'payment_amount')) {
        $fields[] = 'payment_amount';
        $values[] = $packageAmount;
    }
    if (apiTableHasColumn($conn, 'properties', 'images_json')) {
        $fields[] = 'images_json';
        $values[] = json_encode($images, JSON_UNESCAPED_SLASHES);
    }

    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
    $sql = 'INSERT INTO properties (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')';
    $stmt = $conn->prepare($sql);
    $stmt->execute($values);
    $propertyId = (int)$conn->lastInsertId();

    if (!empty($data['rooms']) && is_array($data['rooms'])) {
        if (apiTableHasColumn($conn, 'property_rooms', 'room_type')) {
            $roomStmt = $conn->prepare('
                INSERT INTO property_rooms (property_id, room_type, room_size, price, availability)
                VALUES (?, ?, ?, ?, ?)
            ');

            foreach ($data['rooms'] as $room) {
                $roomType = trim((string)($room['room_type'] ?? ''));
                if ($roomType === '') {
                    continue;
                }

                $roomStmt->execute([
                    $propertyId,
                    $roomType,
                    trim((string)($room['room_size'] ?? '')),
                    isset($room['price']) ? (float)$room['price'] : 0,
                    trim((string)($room['availability'] ?? '')),
                ]);
            }
        } else {
            $roomStmt = $conn->prepare('
                INSERT INTO property_rooms (property_id, category_id, price, availability)
                VALUES (?, ?, ?, ?)
            ');

            foreach ($data['rooms'] as $room) {
                $roomType = trim((string)($room['room_type'] ?? ''));
                if ($roomType === '') {
                    continue;
                }

                $roomStmt->execute([
                    $propertyId,
                    apiRoomCategoryFromType($conn, $roomType),
                    isset($room['price']) ? (float)$room['price'] : 0,
                    isset($room['availability']) ? (int)$room['availability'] : 0,
                ]);
            }
        }
    }

    $conn->commit();

    $response['success'] = true;
    $response['message'] = 'Property submitted successfully!';
    $response['data'] = ['property_id' => $propertyId];
} catch (Throwable $e) {
    if ($conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log('Submit Property Error: ' . $e->getMessage());
}

echo json_encode($response);
