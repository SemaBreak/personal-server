<?php
declare(strict_types=1);

const MAX_ITEMS_PER_FEED = 100;

function childElement(DOMElement $parent, string $localName): ?DOMElement
{
    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMElement && $child->localName === $localName) {
            return $child;
        }
    }

    return null;
}

// First non-empty text among the named direct children.
function childText(DOMElement $parent, string ...$localNames): string
{
    foreach ($localNames as $name) {
        $child = childElement($parent, $name);
        if ($child !== null && trim($child->textContent) !== '') {
            return trim($child->textContent);
        }
    }

    return '';
}

// Plain single-line text, at most $limit characters. Feed text is untrusted.
function cleanText(string $text, int $limit = 200): string
{
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $text) ?? '';
    $text = trim($text);
    if (preg_match('/^.{0,' . $limit . '}/us', $text, $match) === 1) {
        return rtrim($match[0]);
    }

    return '';
}

function safeHttpUrl(string $url): ?string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
        return null;
    }
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
        return null;
    }

    return filter_var($url, FILTER_VALIDATE_URL) === false ? null : $url;
}

function entryLink(DOMElement $entry, bool $isAtom): string
{
    if (!$isAtom) {
        return childText($entry, 'link');
    }
    foreach ($entry->childNodes as $child) {
        if ($child instanceof DOMElement && $child->localName === 'link') {
            $rel = $child->getAttribute('rel');
            if ($rel === '' || $rel === 'alternate') {
                return $child->getAttribute('href');
            }
        }
    }

    return '';
}

// Parses RSS 2.0, RSS 1.0 (RDF) or Atom. Returns ['title' => string, 'items' => [...]] or null.
// Each item is ['guid', 'title', 'link', 'published' (unix time or null)].
function parseFeed(string $xml): ?array
{
    if ($xml === '' || preg_match('/<!ENTITY/i', $xml) === 1) {
        return null;
    }

    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $root = $loaded ? $document->documentElement : null;
    if ($root === null || !in_array($root->localName, ['rss', 'feed', 'RDF'], true)) {
        return null;
    }

    $isAtom = $root->localName === 'feed';
    $channel = $isAtom ? $root : childElement($root, 'channel');
    if ($channel === null) {
        return null;
    }
    $container = $root->localName === 'RDF' ? $root : $channel;

    $items = [];
    foreach ($container->childNodes as $node) {
        if (!$node instanceof DOMElement || $node->localName !== ($isAtom ? 'entry' : 'item')) {
            continue;
        }

        $link = safeHttpUrl(entryLink($node, $isAtom));
        $title = cleanText(childText($node, 'title'));
        if ($link === null || $title === '') {
            continue;
        }

        $guid = trim(childText($node, 'guid', 'id'));
        $date = strtotime(childText($node, 'pubDate', 'published', 'updated', 'date', 'modified'));
        $items[] = [
            'guid' => $guid !== '' && strlen($guid) <= 512 ? $guid : $link,
            'title' => $title,
            'link' => $link,
            'published' => $date === false ? null : min($date, time()),
        ];
        if (count($items) >= MAX_ITEMS_PER_FEED) {
            break;
        }
    }

    return ['title' => cleanText(childText($channel, 'title'), 120), 'items' => $items];
}
