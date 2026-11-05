<?php
declare(strict_types=1);

// Application code lives outside the web root; shared code is in Common/src.
require __DIR__ . '/../../Common/src/http.php';
require __DIR__ . '/../../Common/src/sqlite.php';
require __DIR__ . '/../src/schema.php';
require __DIR__ . '/../src/items.php';

// Authentication is enforced by Nginx (auth_request) before this script runs.
$method = guardApiRequest('np', ['GET', 'POST', 'PUT', 'DELETE']);

$databasePath = configuredDatabasePath('NP_DB_PATH', 'NP');

try {
    $database = openDatabase($databasePath, ITEMS_SCHEMA, 'NP');

    if ($method === 'GET') {
        if (isset($_GET['position'])) {
            $n = filter_var($_GET['position'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($n === false) {
                respond(400, ['error' => 'position must be a positive integer.']);
            }
            $item = nthItem($database, $n);
            if ($item === null) {
                respond(404, ['error' => 'Item not found.']);
            }
            respond(200, $item);
        }

        respond(200, listItems($database));
    }

    $data = requestData();
    if ($data === null) {
        respond(400, ['error' => 'A JSON request body is required.']);
    }

    if ($method === 'POST') {
        $name = validItemName($data->name ?? null);
        $priceCents = validPriceCents($data->price ?? null);
        $link = validLink($data->link ?? null);
        if ($name === null) {
            respond(400, ['error' => 'Name must be 1–120 characters without control characters.']);
        }
        if ($priceCents === null) {
            respond(400, ['error' => 'Price must be a number from 0 to 100000000.']);
        }
        if ($link === null) {
            respond(400, ['error' => 'Link must be a valid http or https URL.']);
        }

        $item = insertItem($database, $name, $priceCents, $link);
        if ($item === null) {
            respond(500, ['error' => 'The item was saved but could not be loaded.']);
        }
        respond(201, $item);
    }

    if ($method === 'PUT') {
        $order = $data->order ?? null;
        if (!is_array($order) || count($order) > 10000) {
            respond(400, ['error' => 'order must be an array of item ids.']);
        }
        foreach ($order as $id) {
            if (!is_int($id) || $id < 1) {
                respond(400, ['error' => 'order must be an array of item ids.']);
            }
        }
        if (!reorderItems($database, $order)) {
            respond(409, ['error' => 'The list changed. Reload and try again.']);
        }
        respond(200, listItems($database));
    }

    // DELETE
    $id = filter_var($data->id ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        respond(400, ['error' => 'A valid item id is required.']);
    }
    if (!deleteItemById($database, $id)) {
        respond(404, ['error' => 'Item not found.']);
    }
    respond(200, ['ok' => true]);
} catch (PDOException $error) {
    error_log('NP database error: ' . $error->getMessage());
    respond(500, ['error' => 'Database request failed. Check the server logs.']);
}
