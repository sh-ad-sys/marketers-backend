<?php
/**
 * Debug - Generate and test new hash
 */

header('Content-Type: text/plain');

$newHash = password_hash('password', PASSWORD_BCRYPT);

echo "NEW hash for 'password':\n$newHash\n\n";

echo "Copy this to your Render ADMIN_PASSWORD env var:\n$newHash\n";
