<?php
declare(strict_types=1);

require __DIR__ . '/../Common/src/http.php';

// Maximum age of the collector's sample before the panel reports it as stale.
const HEALTH_MAX_AGE = 20;
const HEALTH_DEFAULT_PATH = '/run/health/status.json';
const HEALTH_MAX_BYTES = 8192;

// Authentication is enforced by Nginx (auth_request) before this script runs.
guardApiRequest('health', ['GET']);

$path = getenv('HEALTH_STATUS_PATH') ?: HEALTH_DEFAULT_PATH;
$raw = is_readable($path) ? file_get_contents($path, false, null, 0, HEALTH_MAX_BYTES) : false;
$status = is_string($raw) ? json_decode($raw, true) : null;

if (!is_array($status) || !isset($status['generated_at']) || !is_int($status['generated_at'])) {
    respond(503, ['error' => 'The health collector is not running or has not written a sample yet.']);
}

$age = max(0, time() - $status['generated_at']);
$status['age'] = $age;
$status['stale'] = $age > HEALTH_MAX_AGE;
respond(200, $status);
