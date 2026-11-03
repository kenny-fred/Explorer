<?php
declare(strict_types=1);

// --- Environment ---

function loadEnv(): array
{
    if (!file_exists(ENV_FILE)) {
        die('Missing .env file — copy .env.example to .env and set credentials.');
    }
    $env = [];
    foreach (file(ENV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $env[$k] = $v;
    }
    return $env;
}

function loadPasswordHash(string $configuredHash): string
{
    if (!is_file(PASSWORD_FILE)) return $configuredHash;

    $lock = fopen(PASSWORD_LOCK_FILE, 'c');
    if ($lock === false) throw new \RuntimeException('Cannot open password lock file.');
    try {
        if (!flock($lock, LOCK_SH)) throw new \RuntimeException('Cannot lock password configuration.');
        $contents = file_get_contents(PASSWORD_FILE);
        if ($contents === false) throw new \RuntimeException('Cannot read password configuration.');
        try {
            $stored = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Password configuration contains invalid JSON.', 0, $e);
        }
        if (!is_array($stored)
            || !is_string($stored['hash'] ?? null)
            || password_get_info($stored['hash'])['algoName'] === 'unknown') {
            throw new \RuntimeException('Password configuration contains an invalid hash.');
        }
        return $stored['hash'];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function savePasswordHash(string $hash): void
{
    $lock = fopen(PASSWORD_LOCK_FILE, 'c');
    if ($lock === false) throw new \RuntimeException('Cannot open password lock file.');
    try {
        if (!chmod(PASSWORD_LOCK_FILE, 0600)) throw new \RuntimeException('Cannot restrict password lock file permissions.');
        if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Cannot lock password configuration.');
        $temporary = tempnam(DATA_DIR, '.password-');
        if ($temporary === false) throw new \RuntimeException('Cannot create temporary password configuration.');
        try {
            $json = json_encode(['hash' => $hash], JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
                throw new \RuntimeException('Cannot write password configuration.');
            }
            if (!chmod($temporary, 0600)) throw new \RuntimeException('Cannot restrict password configuration permissions.');
            if (!rename($temporary, PASSWORD_FILE)) {
                throw new \RuntimeException('Cannot replace password configuration.');
            }
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function currentInstanceAddress(): array
{
    $requestHost = $_SERVER['HTTP_HOST'] ?? '';
    $parts = $requestHost !== '' ? parse_url('http://' . $requestHost) : false;
    $host = is_array($parts) ? trim((string)($parts['host'] ?? ''), '[]') : '';
    $validHost = filter_var($host, FILTER_VALIDATE_IP)
        || preg_match('/^(?=.{1,253}$)[A-Za-z0-9.-]+$/D', $host);
    $port = is_array($parts) ? ($parts['port'] ?? (int)($_SERVER['SERVER_PORT'] ?? 0)) : 0;
    if (!$validHost
        || !is_array($parts)
        || isset($parts['user'], $parts['pass'], $parts['path'], $parts['query'], $parts['fragment'])
        || !is_int($port)
        || $port < 1
        || $port > 65_535) {
        $host = (string)($_SERVER['SERVER_ADDR'] ?? 'Adresse inconnue');
        $port = (int)($_SERVER['SERVER_PORT'] ?? 0);
    }
    return ['host' => $host, 'port' => $port];
}

function profileAvatarFile(): ?string
{
    foreach (['jpg', 'png', 'webp'] as $extension) {
        $path = PROFILE_DIR . '/avatar.' . $extension;
        if (is_file($path)) return $path;
    }
    return null;
}

function profileAvatarMimeType(string $path): string
{
    return match (pathinfo($path, PATHINFO_EXTENSION)) {
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        default => throw new \RuntimeException('Unsupported profile avatar format.'),
    };
}

// --- Configured peers ---

final class PeerUnavailable extends \RuntimeException {}

function configuredPeers(): array
{
    try {
        $staticPeers = json_decode(PEERS_JSON, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        throw new \InvalidArgumentException('PEERS_JSON must contain a valid JSON array.', 0, $e);
    }

    if (!is_array($staticPeers) || !array_is_list($staticPeers)) {
        throw new \InvalidArgumentException('PEERS_JSON must contain a JSON array.');
    }
    $dynamicPeers = loadDynamicPeers();
    if (($staticPeers !== [] || $dynamicPeers !== []) && !preg_match('/^[A-Za-z0-9._-]{1,64}$/D', APP_USERNAME)) {
        throw new \InvalidArgumentException('USERNAME must use 1 to 64 letters, numbers, dots, underscores, or hyphens when peers are configured.');
    }

    $validated = validatePeerEntries(array_merge(
        markPeers($staticPeers, false),
        markPeers($dynamicPeers, true)
    ));
    return $validated;
}

function markPeers(array $peers, bool $dynamic): array
{
    foreach ($peers as &$peer) {
        if (is_array($peer)) $peer['dynamic'] = $dynamic;
    }
    unset($peer);
    return $peers;
}

function validatePeerEntries(array $peers): array
{
    $names = [];
    $urls = [];
    $tokens = [];
    $validated = [];
    foreach ($peers as $peer) {
        if (!is_array($peer)
            || !is_string($peer['username'] ?? null)
            || !preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $peer['username'])
            || !is_string($peer['url'] ?? null)
            || !is_string($peer['token'] ?? null)
            || !preg_match('/^[A-Za-z0-9._~-]{32,256}$/D', $peer['token'])) {
            throw new \InvalidArgumentException('Each peer needs a valid username, URL, and token of at least 32 characters.');
        }

        $peer['url'] = rtrim($peer['url'], '/');
        $parts = parse_url($peer['url']);
        if (!is_array($parts)) {
            throw new \InvalidArgumentException('Utilisez HTTPS ou HTTP avec un hôte privé/Tailscale et un port explicite.');
        }
        $httpHost = trim($parts['host'] ?? '', '[]');
        $httpHostAllowed = isPrivatePeerIp($httpHost)
            || isTailscalePeerIp($httpHost)
            || strtolower($httpHost) === 'localhost'
            || isTailscalePeerHostname($httpHost);
        $httpHostWithPort = ($parts['scheme'] ?? null) === 'http'
            && isset($parts['port'])
            && $parts['port'] >= 1
            && $parts['port'] <= 65_535
            && $httpHostAllowed;
        if (!filter_var($peer['url'], FILTER_VALIDATE_URL)
            || !isset($parts['scheme'], $parts['host'])
            || ($parts['scheme'] !== 'https' && !$httpHostWithPort)
            || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65_535))
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new \InvalidArgumentException('Utilisez HTTPS ou HTTP avec un hôte privé/Tailscale et un port explicite.');
        }

        if (isset($names[$peer['username']]) || isset($urls[$peer['url']]) || isset($tokens[$peer['token']])) {
            throw new \InvalidArgumentException('Peer usernames, URLs, and tokens must be unique.');
        }
        $names[$peer['username']] = true;
        $urls[$peer['url']] = true;
        $tokens[$peer['token']] = true;
        $peer['token_id'] = hash('sha256', $peer['token']);
        $validated[] = $peer;
    }

    return $validated;
}

function isPrivatePeerIp(string $host): bool
{
    $packed = inet_pton($host);
    if ($packed === false) return false;

    if (strlen($packed) === 4) {
        $octets = array_values(unpack('C4', $packed));
        return $octets[0] === 10
            || ($octets[0] === 172 && $octets[1] >= 16 && $octets[1] <= 31)
            || ($octets[0] === 192 && $octets[1] === 168)
            || $octets[0] === 127;
    }

    if (strlen($packed) === 16) {
        return (ord($packed[0]) & 0xfe) === 0xfc
            || $packed === str_repeat("\0", 15) . "\x01";
    }

    return false;
}

function isTailscalePeerIp(string $host): bool
{
    $packed = inet_pton($host);
    if ($packed === false || strlen($packed) !== 4) return false;

    $octets = array_values(unpack('C4', $packed));
    return $octets[0] === 100 && $octets[1] >= 64 && $octets[1] <= 127;
}

function isTailscalePeerHostname(string $host): bool
{
    return strlen($host) <= 253
        && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+ts\\.net$/iD', $host) === 1;
}

function peerConfigLock(): mixed
{
    $handle = fopen(DATA_DIR . '/.peers.lock', 'c');
    if ($handle === false) throw new \RuntimeException('Cannot open peer configuration lock.');
    return $handle;
}

function loadDynamicPeers(): array
{
    $lock = peerConfigLock();
    try {
        if (!flock($lock, LOCK_SH)) throw new \RuntimeException('Cannot lock peer configuration.');
        return readDynamicPeersFile();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function readDynamicPeersFile(): array
{
    $file = DATA_DIR . '/peers.json';
    if (!file_exists($file)) return [];
    $contents = file_get_contents($file);
    if ($contents === false) throw new \RuntimeException('Cannot read dynamic peer configuration.');
    try {
        $peers = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        throw new \RuntimeException('Dynamic peer configuration contains invalid JSON.', 0, $e);
    }
    if (!is_array($peers) || !array_is_list($peers)) {
        throw new \RuntimeException('Dynamic peer configuration must be a JSON array.');
    }
    return $peers;
}

function updateDynamicPeers(callable $update): array
{
    $lock = peerConfigLock();
    try {
        if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Cannot lock peer configuration.');
        $peers = $update(readDynamicPeersFile());
        if (!is_array($peers) || !array_is_list($peers)) {
            throw new \InvalidArgumentException('Dynamic peer configuration must be a JSON array.');
        }

        $staticPeers = json_decode(PEERS_JSON, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($staticPeers) || !array_is_list($staticPeers)) {
            throw new \InvalidArgumentException('PEERS_JSON must contain a JSON array.');
        }
        validatePeerEntries(array_merge(markPeers($staticPeers, false), markPeers($peers, true)));

        $json = json_encode(array_values($peers), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $file = DATA_DIR . '/peers.json';
        $temporary = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
            if (file_exists($temporary)) unlink($temporary);
            throw new \RuntimeException('Cannot write dynamic peer configuration.');
        }
        if (!chmod($temporary, 0600)) {
            unlink($temporary);
            throw new \RuntimeException('Cannot secure dynamic peer configuration permissions.');
        }
        if (!rename($temporary, $file)) {
            unlink($temporary);
            throw new \RuntimeException('Cannot replace dynamic peer configuration.');
        }
        return $peers;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function findConfiguredPeer(string $username): ?array
{
    foreach (configuredPeers() as $peer) {
        if ($peer['username'] === $username) return $peer;
    }
    return null;
}

function authenticatedPeer(): ?array
{
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $match)) return null;
    $provided = hash('sha256', $match[1]);

    foreach (configuredPeers() as $peer) {
        if (hash_equals($peer['token_id'], $provided)) return $peer;
    }
    return null;
}

function requestPeerJson(array $peer, string $endpoint, string $method = 'GET', ?array $payload = null): array
{
    $headers = "Authorization: Bearer {$peer['token']}\r\nAccept: application/json\r\n";
    $content = '';
    if ($payload !== null) {
        $headers .= "Content-Type: application/json\r\n";
        $content = json_encode($payload, JSON_THROW_ON_ERROR);
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $headers,
            'content' => $content,
            'timeout' => 3,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($peer['url'] . $endpoint, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('{^HTTP/\S+\s+(\d{3})}', $header, $match)) {
            $status = (int)$match[1];
            break;
        }
    }

    if ($body === false) {
        throw new PeerUnavailable("Impossible de joindre {$peer['username']}.");
    }
    if ($status === 403) {
        throw new PeerUnavailable(
            "{$peer['username']} a refusé le token (HTTP 403). Configurez aussi ce pair sur l'autre instance avec le même token partagé."
        );
    }
    if ($status < 200 || $status >= 300) {
        throw new PeerUnavailable("{$peer['username']} a répondu avec le statut HTTP $status.");
    }

    try {
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        throw new PeerUnavailable("La réponse de {$peer['username']} n'est pas un JSON valide.", 0, $e);
    }
    if (!is_array($response)) {
        throw new PeerUnavailable("La réponse de {$peer['username']} est invalide.");
    }

    return $response;
}

function fetchPeerAvatar(array $peer): ?array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer " . $peer['token'] . "\r\nAccept: image/jpeg, image/png, image/webp\r\n",
            'timeout' => 3,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $stream = @fopen($peer['url'] . '/api/peer/avatar', 'rb', false, $context);
    if ($stream === false) throw new PeerUnavailable("Impossible de joindre la photo de {$peer['username']}.");

    try {
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('{^HTTP/\S+\s+(\d{3})}', $header, $match)) {
                $status = (int)$match[1];
                break;
            }
        }
        if ($status === 404) return null;
        if ($status !== 200) {
            throw new PeerUnavailable("La photo de {$peer['username']} a répondu avec le statut HTTP $status.");
        }

        $body = stream_get_contents($stream, 5 * 1024 * 1024 + 1);
        if ($body === false) throw new PeerUnavailable("Impossible de lire la photo de {$peer['username']}.");
        if (strlen($body) > 5 * 1024 * 1024) throw new PeerUnavailable('La photo du correspondant dépasse la taille autorisée.');

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
        $image = getimagesizefromstring($body);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || !is_array($image)
            || ($image['mime'] ?? null) !== $mime
            || ($image[0] ?? 0) < 1
            || ($image[1] ?? 0) < 1
            || ($image[0] ?? 0) > 5000
            || ($image[1] ?? 0) > 5000
            || ($image[0] ?? 0) * ($image[1] ?? 0) > 25_000_000) {
            throw new PeerUnavailable("La photo de {$peer['username']} n'est pas une image valide.");
        }
        return ['mime' => $mime, 'body' => $body];
    } finally {
        fclose($stream);
    }
}

function peerMessagesPath(string $username): string
{
    return DATA_DIR . '/messages/' . hash('sha256', $username) . '.json';
}

function peerMessageReadPath(string $username): string
{
    return DATA_DIR . '/messages/' . hash('sha256', $username) . '.read.json';
}

function openPeerMessages(string $username): mixed
{
    $directory = DATA_DIR . '/messages';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new \RuntimeException('Cannot create peer messages directory.');
    }
    $handle = fopen(peerMessagesPath($username), 'c+');
    if ($handle === false) throw new \RuntimeException('Cannot open peer message history.');
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new \RuntimeException('Cannot lock peer message history.');
    }
    return $handle;
}

function decodePeerMessageReadIds(string $contents): array
{
    if ($contents === '') return [];
    try {
        $ids = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        throw new \RuntimeException('Peer message read state contains invalid JSON.', 0, $e);
    }
    if (!is_array($ids) || !array_is_list($ids) || count($ids) > 500) {
        throw new \RuntimeException('Peer message read state has an invalid format.');
    }
    foreach ($ids as $id) {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new \RuntimeException('Peer message read state contains an invalid entry.');
        }
    }
    return array_fill_keys($ids, true);
}

function loadPeerMessageReadIds(string $username): array
{
    $directory = DATA_DIR . '/messages';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new \RuntimeException('Cannot create peer messages directory.');
    }
    $handle = fopen(peerMessageReadPath($username), 'c+');
    if ($handle === false) throw new \RuntimeException('Cannot open peer message read state.');
    if (!flock($handle, LOCK_SH)) {
        fclose($handle);
        throw new \RuntimeException('Cannot lock peer message read state.');
    }
    try {
        rewind($handle);
        $contents = stream_get_contents($handle);
        if ($contents === false) throw new \RuntimeException('Cannot read peer message read state.');
        return decodePeerMessageReadIds($contents);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function markPeerMessagesRead(string $username, array $messages): void
{
    $directory = DATA_DIR . '/messages';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new \RuntimeException('Cannot create peer messages directory.');
    }
    $handle = fopen(peerMessageReadPath($username), 'c+');
    if ($handle === false) throw new \RuntimeException('Cannot open peer message read state.');
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new \RuntimeException('Cannot lock peer message read state.');
    }
    try {
        rewind($handle);
        $contents = stream_get_contents($handle);
        if ($contents === false) throw new \RuntimeException('Cannot read peer message read state.');
        $readIds = decodePeerMessageReadIds($contents);
        foreach ($messages as $message) {
            if (($message['from'] ?? null) === $username && is_string($message['id'] ?? null)) {
                $readIds[$message['id']] = true;
            }
        }
        $json = json_encode(array_slice(array_keys($readIds), -500), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
            throw new \RuntimeException('Cannot write peer message read state.');
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function hasUnreadPeerMessages(string $username): bool
{
    $readIds = loadPeerMessageReadIds($username);
    foreach (loadPeerMessages($username) as $message) {
        if ($message['from'] === $username && !isset($readIds[$message['id']])) return true;
    }
    return false;
}

function decodePeerMessages(string $contents): array
{
    if ($contents === '') return [];
    try {
        $messages = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        throw new \RuntimeException('Peer message history contains invalid JSON.', 0, $e);
    }
    if (!is_array($messages) || !array_is_list($messages)) {
        throw new \RuntimeException('Peer message history has an invalid format.');
    }
    if (count($messages) > 500) {
        throw new \RuntimeException('Peer message history exceeds its configured limit.');
    }
    foreach ($messages as $message) {
        if (!is_array($message)
            || !is_string($message['id'] ?? null)
            || !preg_match('/^[a-f0-9]{32}$/D', $message['id'])
            || !is_string($message['from'] ?? null)
            || strlen($message['from']) > 64
            || !is_string($message['message'] ?? null)
            || strlen($message['message']) > 8000
            || !is_int($message['timestamp'] ?? null)) {
            throw new \RuntimeException('Peer message history contains an invalid entry.');
        }
    }
    return $messages;
}

function loadPeerMessages(string $username): array
{
    $handle = openPeerMessages($username);
    try {
        rewind($handle);
        $contents = stream_get_contents($handle);
        if ($contents === false) throw new \RuntimeException('Cannot read peer message history.');
        return decodePeerMessages($contents);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function appendPeerMessage(string $username, string $id, string $from, string $message, ?int $timestamp = null): int
{
    $handle = openPeerMessages($username);
    try {
        rewind($handle);
        $contents = stream_get_contents($handle);
        if ($contents === false) throw new \RuntimeException('Cannot read peer message history.');
        $messages = decodePeerMessages($contents);
        foreach ($messages as $existing) {
            if ($existing['id'] === $id) {
                if ($existing['from'] !== $from || $existing['message'] !== $message) {
                    throw new \RuntimeException('Conflicting peer message identifier.');
                }
                return $existing['timestamp'];
            }
        }
        $timestamp ??= time();
        $messages[] = ['id' => $id, 'from' => $from, 'message' => $message, 'timestamp' => $timestamp];
        $messages = array_slice($messages, -500);
        $json = json_encode($messages, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
            throw new \RuntimeException('Cannot write peer message history.');
        }
        return $timestamp;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

// --- Metadata ---

function metaLockHandle(): mixed
{
    static $lock = null;
    if ($lock === null) {
        $lock = fopen(LOCK_FILE, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open metadata lock file: ' . LOCK_FILE);
        }
    }
    return $lock;
}

function loadMeta(): array
{
    flock(metaLockHandle(), LOCK_EX);
    if (!file_exists(DATA_FILE)) return [];
    return json_decode(file_get_contents(DATA_FILE), true) ?: [];
}

function saveMeta(array $meta): void
{
    file_put_contents(DATA_FILE, json_encode(array_values($meta), JSON_PRETTY_PRINT));
    flock(metaLockHandle(), LOCK_UN);
}

function releaseMetaLock(): void
{
    flock(metaLockHandle(), LOCK_UN);
}

function findIndex(array $meta, string $path): int|false
{
    foreach ($meta as $i => $entry) {
        if ($entry['path'] === $path) return $i;
    }
    return false;
}

// --- Path helpers ---

function sanitizeFolder(string $folder): string
{
    $folder = str_replace('\\', '/', $folder);
    $folder = preg_replace('#\.\.+#', '', $folder);
    $folder = preg_replace('#[^\p{L}\p{N}/_.\- ]#u', '', $folder);
    return trim($folder, '/');
}

function autoRename(string $dir, string $filename): string
{
    if (!file_exists($dir . '/' . $filename)) return $filename;
    $ext  = pathinfo($filename, PATHINFO_EXTENSION);
    $base = pathinfo($filename, PATHINFO_FILENAME);
    for ($i = 1; ; $i++) {
        $candidate = $ext ? "$base($i).$ext" : "$base($i)";
        if (!file_exists($dir . '/' . $candidate)) return $candidate;
    }
}

// --- Expiry ---

function expiryTimestamp(string $value): ?int
{
    return match ($value) {
        '1h'  => time() + 3_600,
        '6h'  => time() + 21_600,
        '24h' => time() + 86_400,
        '3d'  => time() + 86_400 * 3,
        '7d'  => time() + 86_400 * 7,
        '30d' => time() + 86_400 * 30,
        default => null,
    };
}

function formatExpiry(?int $ts): string
{
    if ($ts === null) return 'Non';
    $diff = $ts - time();
    if ($diff <= 0) return 'Expired';
    if ($diff < 3_600)  return round($diff / 60) . 'm';
    if ($diff < 86_400) return round($diff / 3_600) . 'h';
    return round($diff / 86_400) . 'd';
}

// --- Upload helpers ---

function phpIniBytes(string $key): int
{
    $val = trim((string)ini_get($key));
    if ($val === '') return PHP_INT_MAX;
    $last = strtolower($val[-1]);
    $n    = (int)$val;
    return match ($last) {
        'g'     => $n * 1024 * 1024 * 1024,
        'm'     => $n * 1024 * 1024,
        'k'     => $n * 1024,
        default => $n,
    };
}

function effectiveMaxUploadBytes(): int
{
    return min(MAX_UPLOAD_BYTES, phpIniBytes('upload_max_filesize'), phpIniBytes('post_max_size'));
}

function formatBytes(int $bytes): string
{
    if ($bytes >= 1024 * 1024 * 1024) return round($bytes / (1024 * 1024 * 1024), 1) . ' GB';
    if ($bytes >= 1024 * 1024)        return round($bytes / (1024 * 1024), 1) . ' MB';
    if ($bytes >= 1024)               return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

// --- Output helpers ---

function clearSessionCookie(): void
{
    setcookie(session_name(), '', [
        'expires' => 1,
        'path' => '/',
        'secure' => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function isHttpsRequest(): bool
{
    return !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
}

function redirect(string $to, ?string $flash = null): never
{
    if ($flash !== null) $_SESSION['flash'] = $flash;
    header('Location: ' . $to);
    exit;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5);
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}
