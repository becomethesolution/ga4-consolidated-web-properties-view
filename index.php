<?php
require __DIR__ . '/lib/bootstrap.php';
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; script-src 'self'; connect-src 'self'; frame-ancestors 'none'");

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}

$loginError = '';
if (!empty($cfg['password_hash']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? '')) && password_verify((string) ($_POST['password'] ?? ''), $cfg['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['ok'] = 1;
        header('Location: ./');
        exit;
    }
    sleep(2); // slow down guessing
    $loginError = 'Wrong password.';
}

$refresh = max(15, (int) ($cfg['realtime_refresh_seconds'] ?? 30));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>GA4 Portfolio Dashboard</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><rect x='1' y='8' width='3' height='7' rx='1' fill='%232a78d6'/><rect x='6.5' y='4' width='3' height='11' rx='1' fill='%232a78d6'/><rect x='12' y='1' width='3' height='14' rx='1' fill='%232a78d6'/></svg>">
<link rel="stylesheet" href="assets/app.css?v=1">
</head>
<?php if (!logged_in($cfg)): ?>
<body class="login">
  <form method="post" class="login-card" autocomplete="off">
    <h1>GA4 Portfolio Dashboard</h1>
    <label for="pw">Password</label>
    <input id="pw" type="password" name="password" autofocus required>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <?php if ($loginError): ?><p class="err"><?= htmlspecialchars($loginError) ?></p><?php endif; ?>
    <button type="submit">Sign in</button>
  </form>
</body>
<?php else: ?>
<body data-refresh="<?= $refresh ?>" data-login="<?= empty($cfg['password_hash']) ? '0' : '1' ?>">
  <header class="top">
    <div class="brand">
      <a href="#/" class="home" id="home-link">All properties</a>
      <span class="crumb" id="crumb"></span>
    </div>
    <div class="live" id="live"><span class="dot"></span><span id="live-text">Connecting…</span></div>
    <div class="controls">
      <select id="prop-select" aria-label="Jump to property"><option value="">All properties</option></select>
      <div class="seg" id="range" role="group" aria-label="Date range">
        <button data-days="7">7d</button><button data-days="28" class="on">28d</button><button data-days="90">90d</button><button data-days="365">12m</button>
      </div>
      <button class="ghost" id="theme" aria-label="Toggle dark mode" title="Toggle dark mode">◐</button>
      <?php if (!empty($cfg['password_hash'])): ?><a class="ghost" href="?logout=1">Sign out</a><?php endif; ?>
    </div>
  </header>
  <div id="banner" class="banner" hidden></div>
  <main id="app"><div class="loading">Loading…</div></main>
  <div id="tip" class="tip" role="tooltip" hidden></div>
  <script src="assets/app.js?v=1"></script>
</body>
<?php endif; ?>
</html>
