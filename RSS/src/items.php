<?php
declare(strict_types=1);

const ITEM_RETENTION_DAYS = 90;
const DEFAULT_PAGE_SIZE = 50;
const MAX_PAGE_SIZE = 100;

function isoTimestamp(?string $stored): ?string
{
    return $stored === null ? null : str_replace(' ', 'T', $stored) . 'Z';
}

function itemRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'feed_id' => (int) $row['feed_id'],
        'feed_title' => (string) $row['feed_title'],
        'title' => (string) $row['title'],
        'link' => (string) $row['link'],
        'published_at' => isoTimestamp((string) $row['published_at']),
    ];
}

// Cursor format: "YYYY-MM-DD HH:MM:SS,id". Returns [published_at, id] or null.
function parseCursor(mixed $value): ?array
{
    if (!is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}),(\d{1,12})$/', $value, $m) !== 1) {
        return null;
    }

    return [$m[1], (int) $m[2]];
}

// One page of items, newest first. 'next' is the cursor for the following page, or null.
function listItems(PDO $database, int $limit, ?array $cursor): array
{
    $sql = 'SELECT items.id, items.feed_id, feeds.title AS feed_title, items.title, items.link, items.published_at
            FROM items JOIN feeds ON feeds.id = items.feed_id';
    if ($cursor !== null) {
        $sql .= ' WHERE items.published_at < :published OR (items.published_at = :same AND items.id < :id)';
    }
    $sql .= ' ORDER BY items.published_at DESC, items.id DESC LIMIT :limit';

    $statement = $database->prepare($sql);
    if ($cursor !== null) {
        $statement->bindValue('published', $cursor[0]);
        $statement->bindValue('same', $cursor[0]);
        $statement->bindValue('id', $cursor[1], PDO::PARAM_INT);
    }
    $statement->bindValue('limit', $limit + 1, PDO::PARAM_INT);
    $statement->execute();
    $rows = $statement->fetchAll();

    $next = null;
    if (count($rows) > $limit) {
        $rows = array_slice($rows, 0, $limit);
        $last = $rows[$limit - 1];
        $next = $last['published_at'] . ',' . $last['id'];
    }

    return ['items' => array_map('itemRow', $rows), 'next' => $next];
}

// Stores parsed items, skipping duplicates and anything older than the retention window.
// Returns the number of new items.
function insertFeedItems(PDO $database, int $feedId, array $items): int
{
    $cutoff = time() - ITEM_RETENTION_DAYS * 86400;
    $statement = $database->prepare(
        'INSERT OR IGNORE INTO items (feed_id, guid, title, link, published_at)
         VALUES (:feed_id, :guid, :title, :link, :published_at)'
    );

    $inserted = 0;
    $database->beginTransaction();
    try {
        foreach ($items as $item) {
            $published = $item['published'] ?? time();
            if ($published < $cutoff) {
                continue;
            }
            $statement->execute([
                'feed_id' => $feedId,
                'guid' => $item['guid'],
                'title' => $item['title'],
                'link' => $item['link'],
                'published_at' => gmdate('Y-m-d H:i:s', $published),
            ]);
            $inserted += $statement->rowCount();
        }
        $database->commit();
    } catch (Throwable $error) {
        $database->rollBack();
        throw $error;
    }

    return $inserted;
}

function pruneOldItems(PDO $database): void
{
    $statement = $database->prepare('DELETE FROM items WHERE published_at < :cutoff');
    $statement->execute(['cutoff' => gmdate('Y-m-d H:i:s', time() - ITEM_RETENTION_DAYS * 86400)]);
}
