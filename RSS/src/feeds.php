<?php
declare(strict_types=1);

const MAX_FEEDS = 100;
const ADD_FEED_BUDGET = 10.0;

function feedRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'url' => (string) $row['url'],
        'title' => (string) $row['title'],
        'last_fetched' => isoTimestamp($row['last_fetched']),
        'last_error' => $row['last_error'] === null ? null : (string) $row['last_error'],
    ];
}

function listFeeds(PDO $database): array
{
    $rows = $database->query(
        'SELECT id, url, title, last_fetched, last_error FROM feeds ORDER BY title COLLATE NOCASE, id'
    )->fetchAll();

    return array_map('feedRow', $rows);
}

function deleteFeedById(PDO $database, int $id): bool
{
    $database->beginTransaction();
    try {
        $database->prepare('DELETE FROM items WHERE feed_id = :id')->execute(['id' => $id]);
        $statement = $database->prepare('DELETE FROM feeds WHERE id = :id');
        $statement->execute(['id' => $id]);
        $database->commit();
    } catch (Throwable $error) {
        $database->rollBack();
        throw $error;
    }

    return $statement->rowCount() > 0;
}

// Fetches and validates a new feed once, then stores it with its first items.
// Responds directly with an explicit error when the feed can't be added.
function subscribeToFeed(PDO $database, string $url): array
{
    if (resolveFetchTarget($url) === null) {
        respond(400, ['error' => 'URL must be a public http(s) address on port 80 or 443.']);
    }
    if ((int) $database->query('SELECT COUNT(*) FROM feeds')->fetchColumn() >= MAX_FEEDS) {
        respond(400, ['error' => 'Feed limit reached. Remove a feed first.']);
    }
    $existing = $database->prepare('SELECT id FROM feeds WHERE url = :url');
    $existing->execute(['url' => $url]);
    if ($existing->fetch() !== false) {
        respond(409, ['error' => 'You already subscribe to this feed.']);
    }

    $result = fetchUrls([0 => ['url' => $url]], ADD_FEED_BUDGET)[0];
    if ($result['error'] !== null) {
        respond(422, ['error' => $result['error']]);
    }
    $feed = parseFeed($result['body']);
    if ($feed === null) {
        respond(422, ['error' => 'That URL is not a valid RSS or Atom feed.']);
    }

    $title = $feed['title'] !== '' ? $feed['title'] : (string) parse_url($url, PHP_URL_HOST);
    $statement = $database->prepare(
        'INSERT INTO feeds (url, title, etag, last_modified, last_fetched)
         VALUES (:url, :title, :etag, :last_modified, :now)'
    );
    $statement->execute([
        'url' => $url,
        'title' => $title,
        'etag' => $result['etag'],
        'last_modified' => $result['last_modified'],
        'now' => gmdate('Y-m-d H:i:s'),
    ]);
    $id = (int) $database->lastInsertId();
    insertFeedItems($database, $id, $feed['items']);

    return feedRow([
        'id' => $id,
        'url' => $url,
        'title' => $title,
        'last_fetched' => gmdate('Y-m-d H:i:s'),
        'last_error' => null,
    ]);
}
