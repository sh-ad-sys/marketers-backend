<?php
/**
 * PlotConnect - Password Hash Generator
 * 
 * 1. Deploy this file to your server root
 * 2. Visit: https://marketers-backend.onrender.com/generate-hash.php
 * 3. Enter your desired password and click Generate
 * 4. Copy the hash into your .env as ADMIN_PASSWORD=<hash>
 * 5. DELETE this file immediately after use
 */

$submitted = $_SERVER['REQUEST_METHOD'] === 'POST';
$pass      = trim($_POST['pass'] ?? '');
$hash      = '';
$error     = '';

// Also diagnose the existing broken hash
$brokenHash   = '$2y$10$vI8A7ugYvjX8rU2R.Yf8eeE1eK4.uX2O0m0Z5C8D4F0uV3wS7h6L.';
$brokenLen    = strlen($brokenHash);
$brokenInfo   = password_get_info($brokenHash);
$brokenValid  = !empty($brokenInfo['algo']);

if ($submitted) {
    if (strlen($pass) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>PlotConnect – Hash Generator</title>
<style>
  body { font-family: monospace; background: #0f172a; color: #e2e8f0; padding: 2rem; }
  h2   { color: #818cf8; }
  .box { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 1.5rem; margin-bottom: 1.5rem; max-width: 640px; }
  label { display: block; margin-bottom: .4rem; color: #94a3b8; font-size:.9rem; }
  input[type=password], input[type=text] {
    width: 100%; padding: .6rem .8rem; background: #0f172a;
    border: 1px solid #475569; border-radius: 6px; color: #f1f5f9;
    font-family: monospace; font-size: .95rem; box-sizing: border-box;
  }
  button { margin-top: .8rem; padding: .6rem 1.4rem; background: #4f46e5;
    color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: .95rem; }
  button:hover { background: #4338ca; }
  .result { background: #064e3b; border: 1px solid #10b981; border-radius: 6px;
    padding: 1rem; margin-top: 1rem; word-break: break-all; }
  .result .label { color: #6ee7b7; font-size:.8rem; margin-bottom:.3rem; }
  .result .val   { color: #f0fdf4; font-size: .9rem; }
  .warn  { background: #7f1d1d; border: 1px solid #ef4444; border-radius: 6px;
    padding: 1rem; margin-top: 1rem; }
  .warn .label { color: #fca5a5; font-size:.8rem; }
  .ok   { color: #4ade80; } .bad { color: #f87171; }
  .env-line { background: #0f172a; padding:.5rem .8rem; border-radius:4px;
    border:1px solid #334155; margin-top:.5rem; word-break:break-all; }
  .delete-warning { color:#fbbf24; margin-top:1.5rem; font-size:.85rem; }
</style>
</head>
<body>

<h2>🔐 PlotConnect — Password Hash Generator</h2>

<!-- Diagnosis of existing hash -->
<div class="box">
  <strong>Diagnosis of your current hash</strong>
  <p>Hash: <code><?= htmlspecialchars($brokenHash) ?></code></p>
  <p>Length: <strong><?= $brokenLen ?> chars</strong>
    <?php if ($brokenLen !== 60): ?>
      <span class="bad">✗ INVALID — bcrypt must be exactly 60 chars. This hash will NEVER verify.</span>
    <?php else: ?>
      <span class="ok">✓ correct length</span>
    <?php endif; ?>
  </p>
  <p>Algo detected: <strong><?= $brokenValid ? htmlspecialchars($brokenInfo['algoName']) : '<span class="bad">none — malformed hash</span>' ?></strong></p>
  <p class="bad">⚠ This hash is broken. Generate a new one below and replace it in your .env.</p>
</div>

<!-- Generator form -->
<div class="box">
  <strong>Generate a new valid hash</strong>
  <form method="POST" style="margin-top:1rem">
    <label>New admin password</label>
    <input type="password" name="pass" placeholder="Enter your desired password" required minlength="6">
    <button type="submit">Generate Hash</button>
  </form>

  <?php if ($error): ?>
    <div class="warn"><span class="bad"><?= htmlspecialchars($error) ?></span></div>
  <?php endif; ?>

  <?php if ($hash): ?>
    <div class="result">
      <div class="label">✓ Valid bcrypt hash (<?= strlen($hash) ?> chars)</div>
      <div class="val"><?= htmlspecialchars($hash) ?></div>
      <div class="label" style="margin-top:.8rem">Copy this into your .env file:</div>
      <div class="env-line">ADMIN_PASSWORD=<?= htmlspecialchars($hash) ?></div>
    </div>
  <?php endif; ?>
</div>

<p class="delete-warning">⚠ DELETE this file from your server immediately after use!</p>

</body>
</html>