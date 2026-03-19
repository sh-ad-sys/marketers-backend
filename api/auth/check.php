<?php
/**
 * PlotConnect - Check Authentication API
 */

require_once dirname(__DIR__, 2) . '/php/config.php';

$userType = getCurrentUserType();

if ($userType === 'admin') {
    jsonResponse(true, 'Authenticated', [
        'user_type' => 'admin',
        'username' => $_SESSION['admin_username']
    ]);
} elseif ($userType === 'marketer') {
    jsonResponse(true, 'Authenticated', [
        'user_type' => 'marketer',
        'name' => $_SESSION['marketer_name'],
        'phone' => $_SESSION['marketer_phone']
    ]);
} else {
    jsonResponse(false, 'Not authenticated', null, 401);
}
