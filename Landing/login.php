<?php
declare(strict_types=1);

require __DIR__ . '/auth_lib.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");

function safeNext(mixed $value): string
{
    if (is_string($value) && preg_match('#^/(?![/\\\\])[^\x00-\x1F\x7F\\\\]*$#', $value) === 1) {
        return $value;
    }

    return '/';
}

$password = configuredPassword();
if ($password === null) {
    error_log('Server auth: SERVER_PASSWORD must be set to at least 16 characters.');
    http_response_code(503);
    echo 'Server is not configured.';
    exit;
}

$next = safeNext($_POST['next'] ?? $_GET['next'] ?? '/');
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (($_POST['action'] ?? '') === 'logout') {
        clearSession();
        header('Location: /login.php', true, 303);
        exit;
    }

    $submitted = $_POST['password'] ?? '';
    if (is_string($submitted) && hash_equals($password, $submitted)) {
        issueSession($password);
        header('Location: ' . $next, true, 303);
        exit;
    }

    sleep(1);
    http_response_code(401);
    $error = 'That password wasn\'t accepted. Try again.';
} elseif (hasValidSession($password)) {
    header('Location: ' . $next, true, 303);
    exit;
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f5f7f5">
  <title>Sign in</title>
  <style>
    :root { color-scheme: light; font-family: "Segoe UI", Arial, sans-serif; color: #23332b; background: #f5f7f5; }
    * { box-sizing: border-box; }
    body { display: grid; min-height: 100vh; margin: 0; place-items: center; padding: 24px; }
    main { width: min(100%, 380px); padding: 32px; border: 1px solid #e0e8e1; border-radius: 16px; background: #fff; box-shadow: 0 16px 40px rgb(35 51 43 / 7%); }
    h1 { margin: 0 0 6px; font-size: 1.6rem; }
    p { margin: 0 0 20px; color: #68776e; }
    form { display: grid; gap: 10px; }
    label { font-size: 13px; font-weight: 600; }
    input { width: 100%; padding: 11px 12px; border: 1px solid #dce7de; border-radius: 8px; font: inherit; }
    input:focus { outline: none; border-color: #8eae98; box-shadow: 0 0 0 3px rgb(53 115 90 / 12%); }
    .error { min-height: 18px; color: #9c574c; font-size: 13px; }
    button { padding: 11px; border: 0; border-radius: 8px; background: #285d46; color: #fff; font: inherit; font-weight: 650; cursor: pointer; }
    button:hover, button:focus-visible { background: #1f4938; }
  </style>
</head>
<body>
  <main>
    <h1>Welcome back</h1>
    <p>Enter your password to continue.</p>
    <form method="post" action="/login.php">
      <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <label for="password">Password</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
      <span class="error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
      <button type="submit">Sign in</button>
    </form>
  </main>
</body>
</html>
