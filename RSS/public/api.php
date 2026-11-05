<?php
declare(strict_types=1);

// Application code lives outside the web root; shared code is in Common/src.
require __DIR__ . '/../../Common/src/http.php';
require __DIR__ . '/../../Common/src/sqlite.php';
require __DIR__ . '/../../Common/src/fetch.php';
require __DIR__ . '/../src/schema.php';
require __DIR__ . '/../src/parse.php';
require __DIR__ . '/../src/items.php';
require __DIR__ . '/../src/feeds.php';
require __DIR__ . '/../src/refresh.php';

// Authentication is enforced by Nginx (auth_request) before this script runs.
$method = guardApiRequest('rss', ['GET', 'POST', 'DELETE']);

$databasePath = configuredDatabasePath('RSS_DB_PATH', 'RSS');

try {
    $database = openDatabase($databasePath, RSS_SCHEMA, 'RSS');

    if ($method === 'GET') {
        if (isset($_GET['feeds'])) {
            respond(200, listFeeds($database));
        }

        $limit = filter_var(
            $_GET['limit'] ?? DEFAULT_PAGE_SIZE,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => MAX_PAGE_SIZE]]
        );
        $cursor = isset($_GET['before']) ? parseCursor($_GET['before']) : null;
        if ($limit === false || (isset($_GET['before']) && $cursor === null)) {
            respond(400, ['error' => 'limit must be 1–' . MAX_PAGE_SIZE . ' and before must be a cursor from a previous response.']);
        }
        respond(200, listItems($database, $limit, $cursor));
    }

    if ($method === 'POST' && isset($_GET['refresh'])) {
        $wait = claimRefresh($database);
        if ($wait > 0) {
            header('Retry-After: ' . $wait);
            respond(429, ['error' => "Feeds were refreshed recently. Try again in $wait seconds.", 'retry_after' => $wait]);
        }
        respond(200, refreshFeeds($database));
    }

    $data = requestData();
    if ($data === null) {
        respond(400, ['error' => 'A JSON request body is required.']);
    }

    if ($method === 'POST') {
        $url = is_string($data->url ?? null) ? trim($data->url) : '';
        if ($url === '') {
            respond(400, ['error' => 'A feed URL is required.']);
        }
        respond(201, subscribeToFeed($database, $url));
    }

    // DELETE
    $id = filter_var($data->id ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        respond(400, ['error' => 'A valid feed id is required.']);
    }
    if (!deleteFeedById($database, $id)) {
        respond(404, ['error' => 'Feed not found.']);
    }
    respond(200, ['ok' => true]);
} catch (PDOException $error) {
    error_log('RSS database error: ' . $error->getMessage());
    respond(500, ['error' => 'Database request failed. Check the server logs.']);
}
