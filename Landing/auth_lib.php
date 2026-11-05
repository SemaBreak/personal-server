<?php
declare(strict_types=1);

// Shared session helpers. Nginx must never serve or execute this file directly.
const SESSION_COOKIE = 'server_session';
const SESSION_LIFETIME = 2592000; // 30 days

function configuredPassword(): ?string
{
    $password = getenv('SERVER_PASSWORD');
    return is_string($password) && strlen($password) >= 16 ? $password : null;
}

function sessionSignature(string $payload, string $password): string
{
    return hash_hmac('sha256', $payload, hash('sha256', 'server-session-key:' . $password, true));
}

function issueSession(string $password): void
{
    $payload = (time() + SESSION_LIFETIME) . '.' . bin2hex(random_bytes(16));
    setcookie(SESSION_COOKIE, $payload . '.' . sessionSignature($payload, $password), [
        'expires' => time() + SESSION_LIFETIME,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function clearSession(): void
{
    setcookie(SESSION_COOKIE, '', [
        'expires' => 1,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function hasValidSession(string $password): bool
{
    $cookie = $_COOKIE[SESSION_COOKIE] ?? '';
    if (!is_string($cookie) || preg_match('/^(\d{1,12})\.([0-9a-f]{32})\.([0-9a-f]{64})$/', $cookie, $m) !== 1) {
        return false;
    }
    if ((int) $m[1] < time()) {
        return false;
    }

    return hash_equals(sessionSignature($m[1] . '.' . $m[2], $password), $m[3]);
}
