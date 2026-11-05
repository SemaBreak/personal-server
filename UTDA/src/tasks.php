<?php
declare(strict_types=1);

const TASK_COLUMNS = 'id, title, completed, created_at';

function taskRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'completed' => (bool) $row['completed'],
        'created_at' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime((string) $row['created_at'] . ' UTC')),
    ];
}

function findTask(PDO $database, int $id): ?array
{
    $statement = $database->prepare('SELECT ' . TASK_COLUMNS . ' FROM tasks WHERE id = :id');
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();

    return $row === false ? null : taskRow($row);
}

function listTasks(PDO $database): array
{
    $rows = $database->query('SELECT ' . TASK_COLUMNS . ' FROM tasks ORDER BY id DESC')->fetchAll();

    return array_map('taskRow', $rows);
}

// Nth oldest open task, where 1 is the oldest.
function nthOldestOpenTask(PDO $database, int $n): ?array
{
    $statement = $database->prepare(
        'SELECT ' . TASK_COLUMNS . ' FROM tasks WHERE completed = 0
         ORDER BY created_at ASC, id ASC LIMIT 1 OFFSET :offset'
    );
    $statement->bindValue('offset', $n - 1, PDO::PARAM_INT);
    $statement->execute();
    $row = $statement->fetch();

    return $row === false ? null : taskRow($row);
}

function insertTask(PDO $database, string $title): ?array
{
    $statement = $database->prepare('INSERT INTO tasks (title) VALUES (:title)');
    $statement->execute(['title' => $title]);

    return findTask($database, (int) $database->lastInsertId());
}

function updateTaskCompleted(PDO $database, int $id, bool $completed): ?array
{
    $statement = $database->prepare('UPDATE tasks SET completed = :completed WHERE id = :id');
    $statement->execute(['completed' => $completed ? 1 : 0, 'id' => $id]);

    return findTask($database, $id);
}

function deleteTaskById(PDO $database, int $id): bool
{
    $statement = $database->prepare('DELETE FROM tasks WHERE id = :id');
    $statement->execute(['id' => $id]);

    return $statement->rowCount() > 0;
}

function deleteCompletedTasks(PDO $database): void
{
    $database->exec('DELETE FROM tasks WHERE completed = 1');
}

// Returns the trimmed title when valid, or null.
function validTaskTitle(mixed $title): ?string
{
    if (!is_string($title)) {
        return null;
    }

    $title = trim($title);
    $characterCount = preg_match_all('/./us', $title);
    if ($title === '' || $characterCount === false || $characterCount > 120 || preg_match('/[\x00-\x1F\x7F]/', $title) === 1) {
        return null;
    }

    return $title;
}
