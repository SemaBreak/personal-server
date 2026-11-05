<?php
declare(strict_types=1);

// Internal endpoint for Nginx auth_request: 204 = signed in, 401 = not signed in.
require __DIR__ . '/auth_lib.php';

header('Cache-Control: no-store');

$password = configuredPassword();
if ($password === null) {
    error_log('Server auth: SERVER_PASSWORD must be set to at least 16 characters.');
    http_response_code(500);
    exit;
}

http_response_code(hasValidSession($password) ? 204 : 401);
