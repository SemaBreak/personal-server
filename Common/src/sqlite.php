<?php
declare(strict_types=1);

function isAbsolutePath(string $path): bool
{
    if (DIRECTORY_SEPARATOR === '\\') {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\)/', $path) === 1;
    }

    return str_starts_with($path, '/');
}

function resolveExistingPath(string $path): ?string
{
    while (!file_exists($path) && !is_link($path)) {
        $parent = dirname($path);
        if ($parent === $path) {
            return null;
        }
        $path = $parent;
    }

    $resolved = realpath($path);
    return $resolved === false ? null : $resolved;
}

function isWithinPath(string $path, string $root): bool
{
    $root = rtrim($root, '/\\');
    $rootPrefix = $root . DIRECTORY_SEPARATOR;

    return strcasecmp($path, $root) === 0
        || strncasecmp($path, $rootPrefix, strlen($rootPrefix)) === 0;
}

// Validates the database path in environment variable $envName and returns it,
// or responds with an explicit error. $appName is used only in log messages.
function configuredDatabasePath(string $envName, string $appName): string
{
    $databasePath = getenv($envName);
    if ($databasePath === false || $databasePath === '') {
        respond(503, ['error' => "Server is not configured. Set $envName to a path outside the web root."]);
    }
    if (!isAbsolutePath($databasePath)) {
        respond(503, ['error' => "$envName must be an absolute path outside the web root."]);
    }

    $directory = dirname($databasePath);
    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $resolvedDirectory = resolveExistingPath($directory);
    if ($documentRoot === false || $resolvedDirectory === null) {
        respond(503, ['error' => 'The web server must provide a valid document root and database directory.']);
    }
    if (isWithinPath($resolvedDirectory, $documentRoot)) {
        respond(500, ['error' => "$envName must be outside the web root."]);
    }
    if (is_link($databasePath) || (file_exists($databasePath) && is_dir($databasePath))) {
        respond(500, ['error' => "$envName must be a regular database file path."]);
    }
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        error_log("$appName: could not create the SQLite database directory.");
        respond(500, ['error' => 'Database directory is unavailable. Check the server configuration.']);
    }

    return $databasePath;
}

// Opens the SQLite database, runs the app's idempotent $schemaSql and restricts file permissions.
function openDatabase(string $databasePath, string $schemaSql, string $appName): PDO
{
    $database = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $database->exec('PRAGMA busy_timeout = 5000');
    $database->exec($schemaSql);
    if (DIRECTORY_SEPARATOR !== '\\' && !@chmod($databasePath, 0600)) {
        error_log("$appName: could not restrict the SQLite database file permissions.");
    }

    return $database;
}
