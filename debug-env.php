<?php
/**
 * Debug - Check password
 */

require_once 'config.php';

header('Content-Type: text/plain');

$hash = getenv('ADMIN_PASSWORD');

echo "Current hash: $hash\n\n";

echo "Testing 'password': " . (password_verify('password', $hash) ? 'MATCH ✓' : 'NO MATCH ✗') . "\n";
