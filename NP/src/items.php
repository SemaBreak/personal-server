<?php
declare(strict_types=1);

const ITEM_COLUMNS = 'id, name, price_cents, link, position';

function itemRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'price' => round(((int) $row['price_cents']) / 100, 2),
        'link' => (string) $row['link'],
        'position' => (int) $row['position'],
    ];
}

function findItem(PDO $database, int $id): ?array
{
    $statement = $database->prepare('SELECT ' . ITEM_COLUMNS . ' FROM items WHERE id = :id');
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();

    return $row === false ? null : itemRow($row);
}

// Items in planned purchase order.
function listItems(PDO $database): array
{
    $rows = $database->query('SELECT ' . ITEM_COLUMNS . ' FROM items ORDER BY position ASC, id ASC')->fetchAll();

    return array_map('itemRow', $rows);
}

// Nth item in the plan, where 1 is the next to buy.
function nthItem(PDO $database, int $n): ?array
{
    $statement = $database->prepare(
        'SELECT ' . ITEM_COLUMNS . ' FROM items ORDER BY position ASC, id ASC LIMIT 1 OFFSET :offset'
    );
    $statement->bindValue('offset', $n - 1, PDO::PARAM_INT);
    $statement->execute();
    $row = $statement->fetch();

    return $row === false ? null : itemRow($row);
}

// Appends the item at the end of the plan.
function insertItem(PDO $database, string $name, int $priceCents, string $link): ?array
{
    $statement = $database->prepare(
        'INSERT INTO items (name, price_cents, link, position)
         VALUES (:name, :price_cents, :link, (SELECT COALESCE(MAX(position), 0) + 1 FROM items))'
    );
    $statement->execute(['name' => $name, 'price_cents' => $priceCents, 'link' => $link]);

    return findItem($database, (int) $database->lastInsertId());
}

function deleteItemById(PDO $database, int $id): bool
{
    $statement = $database->prepare('DELETE FROM items WHERE id = :id');
    $statement->execute(['id' => $id]);

    return $statement->rowCount() > 0;
}

// Applies a new order. $ids must contain every existing item id exactly once.
function reorderItems(PDO $database, array $ids): bool
{
    $database->beginTransaction();
    try {
        $existing = array_map('intval', $database->query('SELECT id FROM items')->fetchAll(PDO::FETCH_COLUMN));
        $expected = $existing;
        $provided = $ids;
        sort($expected);
        sort($provided);
        if ($expected !== $provided) {
            $database->rollBack();
            return false;
        }

        $statement = $database->prepare('UPDATE items SET position = :position WHERE id = :id');
        foreach ($ids as $index => $id) {
            $statement->execute(['position' => $index + 1, 'id' => $id]);
        }
        $database->commit();
    } catch (Throwable $error) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $error;
    }

    return true;
}

// Returns the trimmed name when valid, or null.
function validItemName(mixed $name): ?string
{
    if (!is_string($name)) {
        return null;
    }

    $name = trim($name);
    $characterCount = preg_match_all('/./us', $name);
    if ($name === '' || $characterCount === false || $characterCount > 120 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
        return null;
    }

    return $name;
}

// Returns the price in cents when valid, or null.
function validPriceCents(mixed $price): ?int
{
    if (!is_int($price) && !is_float($price)) {
        return null;
    }
    if (!is_finite((float) $price) || $price < 0 || $price > 100000000) {
        return null;
    }

    return (int) round($price * 100);
}

// Returns the link ('' for none) when it is an http(s) URL, or null. Blocks javascript: and similar schemes.
function validLink(mixed $link): ?string
{
    if ($link === null) {
        return '';
    }
    if (!is_string($link)) {
        return null;
    }

    $link = trim($link);
    if ($link === '') {
        return '';
    }
    if (strlen($link) > 2048 || preg_match('/[\x00-\x20\x7F]/', $link) === 1) {
        return null;
    }
    $scheme = parse_url($link, PHP_URL_SCHEME);
    if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
        return null;
    }
    if (filter_var($link, FILTER_VALIDATE_URL) === false) {
        return null;
    }

    return $link;
}
