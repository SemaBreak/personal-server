<?php
declare(strict_types=1);

// Safe outbound HTTP for apps that fetch user-supplied URLs (SSRF-hardened).
// Only http/https on ports 80/443, public IPv4 targets, pinned DNS results,
// manually validated redirects, and size and time limits.

const FETCH_MAX_BYTES = 2097152;
const FETCH_MAX_REDIRECTS = 3;
const FETCH_CONNECT_TIMEOUT = 5;
const FETCH_REQUEST_TIMEOUT = 8;

function isPublicIpv4(string $ip): bool
{
    $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
        return false;
    }

    // 100.64.0.0/10 is carrier-grade NAT space, which includes Tailscale addresses.
    $long = ip2long($ip);
    return $long !== false && ($long & 0xFFC00000) !== 0x64400000;
}

// Validates a URL and resolves its host. Returns [host, port, ip] or null if the target is unsafe.
function resolveFetchTarget(string $url): ?array
{
    if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
        return null;
    }
    $parts = parse_url($url);
    if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
        return null;
    }

    $scheme = strtolower($parts['scheme'] ?? '');
    $host = strtolower($parts['host'] ?? '');
    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    if (!in_array($scheme, ['http', 'https'], true) || $host === '' || !in_array($port, [80, 443], true)) {
        return null;
    }

    $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);
    if ($addresses === []) {
        return null;
    }
    foreach ($addresses as $address) {
        if (!isPublicIpv4($address)) {
            return null;
        }
    }

    return ['host' => $host, 'port' => $port, 'ip' => $addresses[0]];
}

function resolveRedirect(string $base, string $location): ?string
{
    $location = trim($location);
    if ($location === '' || preg_match('/[\x00-\x20\x7F]/', $location) === 1) {
        return null;
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
        return $location;
    }

    $parts = parse_url($base);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        return null;
    }
    if (str_starts_with($location, '//')) {
        return $parts['scheme'] . ':' . $location;
    }

    $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    if (str_starts_with($location, '/')) {
        return $origin . $location;
    }
    $path = $parts['path'] ?? '/';

    return $origin . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
}

function fetchResult(int $status, string $body = '', ?string $etag = null, ?string $lastModified = null, ?string $error = null): array
{
    return ['status' => $status, 'body' => $body, 'etag' => $etag, 'last_modified' => $lastModified, 'error' => $error];
}

// Fetches many URLs in parallel within $budgetSeconds. $requests maps a key to
// ['url' => string, 'etag' => ?string, 'last_modified' => ?string]; the result maps the
// same keys to ['status', 'body', 'etag', 'last_modified', 'error'] (error is null on success).
function fetchUrls(array $requests, float $budgetSeconds): array
{
    $deadline = microtime(true) + $budgetSeconds;
    $results = [];
    $pending = [];
    foreach ($requests as $key => $request) {
        $pending[$key] = $request + ['etag' => null, 'last_modified' => null, 'redirects' => 0];
    }

    while ($pending !== []) {
        $remaining = $deadline - microtime(true);
        if ($remaining < 0.5) {
            foreach ($pending as $key => $request) {
                $results[$key] = fetchResult(0, error: 'Timed out before this feed could be fetched.');
            }
            break;
        }

        $multi = curl_multi_init();
        $handles = [];
        $states = [];
        foreach ($pending as $key => $request) {
            $target = resolveFetchTarget($request['url']);
            if ($target === null) {
                $results[$key] = fetchResult(0, error: 'URL must be a public http(s) address.');
                continue;
            }

            $state = new stdClass();
            $state->body = '';
            $state->headers = [];
            $state->tooLarge = false;

            $headers = ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, */*;q=0.5'];
            if (is_string($request['etag']) && $request['etag'] !== '') {
                $headers[] = 'If-None-Match: ' . $request['etag'];
            }
            if (is_string($request['last_modified']) && $request['last_modified'] !== '') {
                $headers[] = 'If-Modified-Since: ' . $request['last_modified'];
            }

            $handle = curl_init($request['url']);
            curl_setopt_array($handle, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$target['ip']}"],
                CURLOPT_CONNECTTIMEOUT => FETCH_CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => max(1, (int) floor(min(FETCH_REQUEST_TIMEOUT, $remaining))),
                CURLOPT_USERAGENT => 'ServerApps-Feed/1.0',
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_ENCODING => '',
                CURLOPT_HEADERFUNCTION => function ($handle, string $line) use ($state): int {
                    if (str_starts_with($line, 'HTTP/')) {
                        $state->headers = [];
                    } elseif (str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $state->headers[strtolower(trim($name))] = trim($value);
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use ($state): int {
                    $state->body .= $chunk;
                    if (strlen($state->body) > FETCH_MAX_BYTES) {
                        $state->tooLarge = true;
                        return 0;
                    }
                    return strlen($chunk);
                },
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[$key] = $handle;
            $states[$key] = $state;
        }

        $finished = [];
        do {
            $status = curl_multi_exec($multi, $running);
            while (($info = curl_multi_info_read($multi)) !== false) {
                $finished[spl_object_id($info['handle'])] = $info['result'];
            }
            if ($running > 0) {
                curl_multi_select($multi, 0.5);
            }
        } while ($running > 0 && $status === CURLM_OK && microtime(true) < $deadline + 1);
        while (($info = curl_multi_info_read($multi)) !== false) {
            $finished[spl_object_id($info['handle'])] = $info['result'];
        }

        $next = [];
        foreach ($handles as $key => $handle) {
            $state = $states[$key];
            $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $outcome = $finished[spl_object_id($handle)] ?? null;
            $curlError = curl_error($handle);
            curl_multi_remove_handle($multi, $handle);

            if ($state->tooLarge) {
                $results[$key] = fetchResult(0, error: 'Feed is larger than 2 MB.');
            } elseif ($outcome === null) {
                $results[$key] = fetchResult(0, error: 'Timed out.');
            } elseif ($outcome !== 0) {
                $results[$key] = fetchResult(0, error: $curlError !== '' ? 'Request failed: ' . $curlError : 'Request failed.');
            } elseif (in_array($code, [301, 302, 303, 307, 308], true) && isset($state->headers['location'])) {
                $target = $pending[$key]['redirects'] < FETCH_MAX_REDIRECTS
                    ? resolveRedirect($pending[$key]['url'], $state->headers['location'])
                    : null;
                if ($target === null) {
                    $results[$key] = fetchResult(0, error: 'Too many or invalid redirects.');
                } else {
                    $next[$key] = ['url' => $target, 'redirects' => $pending[$key]['redirects'] + 1] + $pending[$key];
                }
            } elseif ($code === 304) {
                $results[$key] = fetchResult(304);
            } elseif ($code >= 200 && $code < 300) {
                $results[$key] = fetchResult(
                    $code,
                    $state->body,
                    $state->headers['etag'] ?? null,
                    $state->headers['last-modified'] ?? null
                );
            } else {
                $results[$key] = fetchResult($code, error: "Server responded with HTTP $code.");
            }
        }
        unset($handles, $multi);
        $pending = $next;
    }

    return $results;
}
