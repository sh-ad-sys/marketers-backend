<?php
/**
 * Debug - Test basic password hashing
 */

header('Content-Type: text/plain');

$testHash = password_hash('password', PASSWORD_BCRYPT);

echo "PHP Version: " . phpversion() . "\n\n";

echo "New hash for 'password': $testHash\n\n";

echo "Verify 'password' against new hash: " . (password_verify('password', $testHash) ? 'MATCH ✓' : 'NO MATCH ✗') . "\n";
