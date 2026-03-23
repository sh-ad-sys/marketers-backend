<?php
/**
 * Password Hash Generator
 * Visit this file to generate a bcrypt hash for your password
 */

$hash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if (strlen($password) >= 1) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Generate Password Hash</title>
    <style>
        body { font-family: sans-serif; padding: 20px; max-width: 500px; margin: 0 auto; }
        input { padding: 10px; width: 100%; margin-bottom: 10px; box-sizing: border-box; }
        button { padding: 10px 20px; background: #4f46e5; color: white; border: none; cursor: pointer; }
        .result { margin-top: 20px; padding: 15px; background: #e0e7ff; word-break: break-all; }
    </style>
</head>
<body>
    <h2>Password Hash Generator</h2>
    <form method="POST">
        <input type="password" name="password" placeholder="Enter password" required>
        <button type="submit">Generate Hash</button>
    </form>
    
    <?php if ($hash): ?>
        <div class="result">
            <strong>Hash:</strong><br>
            <?= htmlspecialchars($hash) ?>
        </div>
    <?php endif; ?>
</body>
</html>
