<?php
declare(strict_types=1);

// A refresh may start at most once per interval. The interval is longer than the
// time budget, so it also prevents overlapping refreshes without a separate lock.
const REFRESH_MIN_INTERVAL = 30;
const REFRESH_BUDGET = 20.0;

// Atomically claims the right to refresh. Returns 0 on success, or the seconds to wait.
function claimRefresh(PDO $database): int
{
    $now = time();
    $statement = $database->prepare(
        "UPDATE meta SET value = :now WHERE name = 'refresh_started' AND CAST(value AS INTEGER) <= :threshold"
    );
    $statement->execute(['now' => (string) $now, 'threshold' => $now - REFRESH_MIN_INTERVAL]);
    if ($statement->rowCount() === 1) {
        return 0;
    }

    $started = (int) $database->query("SELECT value FROM meta WHERE name = 'refresh_started'")->fetchColumn();
    return max(1, $started + REFRESH_MIN_INTERVAL - $now);
}

// Stores one fetch outcome for a feed. Returns ['new' => int, 'error' => ?string].
function storeFeedResult(PDO $database, int $feedId, array $result): array
{
    $now = gmdate('Y-m-d H:i:s');
    if ($result['error'] === null && $result['status'] === 304) {
        $database->prepare('UPDATE feeds SET last_fetched = :now, last_error = NULL WHERE id = :id')
            ->execute(['now' => $now, 'id' => $feedId]);
        return ['new' => 0, 'error' => null];
    }

    $feed = $result['error'] === null ? parseFeed($result['body']) : null;
    $error = $result['error'] ?? ($feed === null ? 'Not a valid RSS or Atom feed.' : null);
    if ($error !== null) {
        $database->prepare('UPDATE feeds SET last_error = :error WHERE id = :id')
            ->execute(['error' => $error, 'id' => $feedId]);
        return ['new' => 0, 'error' => $error];
    }

    $new = insertFeedItems($database, $feedId, $feed['items']);
    $database->prepare(
        "UPDATE feeds SET title = COALESCE(NULLIF(:title, ''), title), etag = :etag,
         last_modified = :last_modified, last_fetched = :now, last_error = NULL WHERE id = :id"
    )->execute([
        'title' => $feed['title'],
        'etag' => $result['etag'],
        'last_modified' => $result['last_modified'],
        'now' => $now,
        'id' => $feedId,
    ]);

    return ['new' => $new, 'error' => null];
}

// Fetches every feed in parallel and stores the results.
function refreshFeeds(PDO $database): array
{
    set_time_limit(40);
    ignore_user_abort(true);

    $feeds = $database->query('SELECT id, url, etag, last_modified FROM feeds')->fetchAll();
    $requests = [];
    foreach ($feeds as $feed) {
        $requests[(int) $feed['id']] = [
            'url' => $feed['url'],
            'etag' => $feed['etag'],
            'last_modified' => $feed['last_modified'],
        ];
    }
    $results = fetchUrls($requests, REFRESH_BUDGET);

    $summary = ['feeds' => count($feeds), 'new_items' => 0, 'errors' => 0];
    foreach ($feeds as $feed) {
        $outcome = storeFeedResult($database, (int) $feed['id'], $results[(int) $feed['id']]);
        $summary['new_items'] += $outcome['new'];
        $summary['errors'] += $outcome['error'] === null ? 0 : 1;
    }
    pruneOldItems($database);

    return $summary;
}
