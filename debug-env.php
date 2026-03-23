<?php
/**
 * Debug - Test different passwords
 */

require_once 'config.php';

header('Content-Type: text/plain');

$hash = getenv('ADMIN_PASSWORD');

$testPasswords = ['password', 'Password', 'PASSWORD', 'pass', 'admin', 'test'];

foreach ($testPasswords as $pwd) {
    $result = password_verify($pwd, $hash) ? '✓ MATCH' : '✗ no match';
    echo "$pwd : $result\n";
}

echo "\n";
echo "Hash: $hash\n";
echo "New hash for 'password': " . password_hash('password', PASSWORD_BCRYPT) . "\n";
