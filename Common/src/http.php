<?php
declare(strict_types=1);

const MAX_REQUEST_BYTES = 16384;

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function requestData(): ?stdClass
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > MAX_REQUEST_BYTES) {
        respond(413, ['error' => 'Request is too large.']);
    }

    $stream = fopen('php://input', 'rb');
    if ($stream === false) {
        respond(400, ['error' => 'Request body could not be read.']);
    }

    $raw = fread($stream, MAX_REQUEST_BYTES + 1);
    fclose($stream);
    if ($raw === false) {
        respond(400, ['error' => 'Request body could not be read.']);
    }
    if (strlen($raw) > MAX_REQUEST_BYTES) {
        respond(413, ['error' => 'Request is too large.']);
    }
    if (trim($raw) === '') {
        return null;
    }
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (preg_match('/^\s*application\/json\s*(?:;|$)/i', $contentType) !== 1) {
        respond(415, ['error' => 'Request body must use application/json.']);
    }

    try {
        $data = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        respond(400, ['error' => 'Request body must be valid JSON.']);
    }

    if (!$data instanceof stdClass) {
        respond(400, ['error' => 'Request body must be a JSON object.']);
    }

    return $data;
}

// Sends the standard JSON API headers, then rejects disallowed methods and
// non-GET requests lacking the app's X-Requested-With value (CSRF defence in
// depth for cookie sessions). Returns the request method.
function guardApiRequest(string $appName, array $allowedMethods): string
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, $allowedMethods, true)) {
        header('Allow: ' . implode(', ', $allowedMethods));
        respond(405, ['error' => 'Method not allowed.']);
    }
    if ($method !== 'GET' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== $appName) {
        respond(403, ['error' => 'Missing required request header.']);
    }

    return $method;
}
