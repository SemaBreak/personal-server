<?php
declare(strict_types=1);

// Application code lives outside the web root; shared code is in Common/src.
require __DIR__ . '/../../Common/src/http.php';
require __DIR__ . '/../../Common/src/sqlite.php';
require __DIR__ . '/../src/schema.php';
require __DIR__ . '/../src/tasks.php';

// Authentication is enforced by Nginx (auth_request) before this script runs.
$method = guardApiRequest('daylight', ['GET', 'POST', 'PATCH', 'DELETE']);

$databasePath = configuredDatabasePath('DAYLIGHT_DB_PATH', 'Daylight');

try {
    $database = openDatabase($databasePath, TASKS_SCHEMA, 'Daylight');

    if ($method === 'GET') {
        if (isset($_GET['oldest'])) {
            $n = filter_var($_GET['oldest'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($n === false) {
                respond(400, ['error' => 'oldest must be a positive integer.']);
            }
            $task = nthOldestOpenTask($database, $n);
            if ($task === null) {
                respond(404, ['error' => 'Task not found.']);
            }
            respond(200, $task);
        }

        respond(200, listTasks($database));
    }

    if ($method === 'POST') {
        $data = requestData();
        if ($data === null || !is_string($data->title ?? null)) {
            respond(400, ['error' => 'Task title is required.']);
        }
        $title = validTaskTitle($data->title);
        if ($title === null) {
            respond(400, ['error' => 'Task title must be 1–120 characters without control characters.']);
        }

        $task = insertTask($database, $title);
        if ($task === null) {
            respond(500, ['error' => 'The task was saved but could not be loaded.']);
        }
        respond(201, $task);
    }

    if ($method === 'PATCH') {
        $data = requestData();
        if ($data === null) {
            respond(400, ['error' => 'A valid task id and completed status are required.']);
        }
        $id = filter_var($data->id ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || !is_bool($data->completed ?? null)) {
            respond(400, ['error' => 'A valid task id and completed status are required.']);
        }

        $task = updateTaskCompleted($database, $id, $data->completed);
        if ($task === null) {
            respond(404, ['error' => 'Task not found.']);
        }
        respond(200, $task);
    }

    // DELETE: a body with an id removes one task; no body clears completed tasks.
    $data = requestData();
    if ($data === null) {
        deleteCompletedTasks($database);
        respond(200, ['ok' => true]);
    }

    $id = filter_var($data->id ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        respond(400, ['error' => 'A valid task id is required.']);
    }
    if (!deleteTaskById($database, $id)) {
        respond(404, ['error' => 'Task not found.']);
    }
    respond(200, ['ok' => true]);
} catch (PDOException $error) {
    error_log('Daylight database error: ' . $error->getMessage());
    respond(500, ['error' => 'Database request failed. Check the server logs.']);
}
