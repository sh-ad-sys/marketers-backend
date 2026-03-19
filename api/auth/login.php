<?php
/**
 * PlotConnect - Login API
 */

require_once dirname(__DIR__, 2) . '/php/config.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

// Get input data
$data = json_decode(file_get_contents('php://input'), true);
$type = isset($data['type']) ? $data['type'] : '';
$username = isset($data['username']) ? sanitize($data['username']) : '';
$password = isset($data['password']) ? $data['password'] : '';

// Validate input
if (empty($type) || empty($password)) {
    jsonResponse(false, 'Please fill in all required fields');
}

if ($type === 'admin' && empty($username)) {
    jsonResponse(false, 'Please fill in all required fields');
}

if ($type === 'marketer') {
    $name = isset($data['name']) ? sanitize($data['name']) : '';
    $phone = isset($data['phone']) ? sanitize($data['phone']) : '';
    
    if (empty($name) && empty($phone)) {
        jsonResponse(false, 'Please fill in all required fields');
    }
}

$conn = getDBConnection();

if ($type === 'admin') {
    // Admin login
    $stmt = $conn->prepare("SELECT id, username, password, full_name FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    $result = $stmt->fetch();
    
    if ($result) {
        if (verifyPassword($password, $result['password'])) {
            $_SESSION['admin_id'] = $result['id'];
            $_SESSION['admin_username'] = $result['username'];
            $_SESSION['user_type'] = 'admin';
            
            jsonResponse(true, 'Login successful', [
                'user_type' => 'admin',
                'username' => $result['username'],
                'full_name' => $result['full_name']
            ]);
        } else {
            jsonResponse(false, 'Invalid password');
        }
    } else {
        jsonResponse(false, 'Invalid username');
    }
} elseif ($type === 'marketer') {
    // Marketer login - accept either name OR phone
    $name = isset($data['name']) ? sanitize($data['name']) : '';
    $phone = isset($data['phone']) ? sanitize($data['phone']) : '';
    
    if (empty($name) && empty($phone)) {
        jsonResponse(false, 'Please enter name or phone number');
    }
    
    // Try to find by name or phone
    if (!empty($name)) {
        $stmt = $conn->prepare("SELECT id, name, phone, password FROM marketers WHERE name = ? AND is_active = 1");
        $stmt->execute([$name]);
    } else {
        $stmt = $conn->prepare("SELECT id, name, phone, password FROM marketers WHERE phone = ? AND is_active = 1");
        $stmt->execute([$phone]);
    }
    $result = $stmt->fetch();
    
    if ($result) {
        if (verifyPassword($password, $result['password'])) {
            $_SESSION['marketer_id'] = $result['id'];
            $_SESSION['marketer_name'] = $result['name'];
            $_SESSION['marketer_phone'] = $result['phone'];
            $_SESSION['user_type'] = 'marketer';
            
            jsonResponse(true, 'Login successful', [
                'user_type' => 'marketer',
                'name' => $result['name'],
                'phone' => $result['phone']
            ]);
        } else {
            jsonResponse(false, 'Invalid password');
        }
    } else {
        jsonResponse(false, 'Invalid credentials. Please contact admin to register.');
    }
} else {
    jsonResponse(false, 'Invalid user type');
}
