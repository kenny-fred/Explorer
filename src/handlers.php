<?php
declare(strict_types=1);

// --- Auth ---

function isLoggedIn(): bool
{
    return !empty($_SESSION['logged_in']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: /');
        exit;
    }
}

function verifyCsrf(): void
{
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}

// --- Route handlers ---

function handleDashboard(): void
{
    if (!isLoggedIn()) { renderLogin(); return; }
    $meta = loadMeta();
    releaseMetaLock();
    usort($meta, fn($a, $b) => $b['uploaded'] <=> $a['uploaded']);
    renderDashboard($meta);
}

function handleLogin(): void
{
    verifyCsrf();
    usleep(300_000);
    if (($_POST['username'] ?? '') === APP_USERNAME && password_verify($_POST['password'] ?? '', APP_PASSWORD)) {
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
        redirect('/');
    }
    renderLogin('Invalid credentials.');
}

function handleLogout(): void
{
    session_unset();
    session_destroy();
    clearSessionCookie();
    redirect('/');
}

function handleChangePassword(): void
{
    requireLogin();
    verifyCsrf();

    $currentPassword = $_POST['current_password'] ?? null;
    $newPassword = $_POST['new_password'] ?? null;
    $confirmPassword = $_POST['confirm_password'] ?? null;
    if (!is_string($currentPassword) || !is_string($newPassword) || !is_string($confirmPassword)) {
        redirect('/discover?account=password', 'Tous les champs du changement de mot de passe sont obligatoires.');
    }
    if (!password_verify($currentPassword, APP_PASSWORD)) {
        redirect('/discover?account=password', 'Le mot de passe actuel est incorrect.');
    }
    if (strlen($newPassword) < 12 || strlen($newPassword) > 1024) {
        redirect('/discover?account=password', 'Le nouveau mot de passe doit contenir au moins 12 caractères.');
    }
    if (!hash_equals($newPassword, $confirmPassword)) {
        redirect('/discover?account=password', 'La confirmation du nouveau mot de passe ne correspond pas.');
    }

    savePasswordHash(password_hash($newPassword, PASSWORD_DEFAULT));
    session_regenerate_id(true);
    redirect('/discover?account=password', 'Mot de passe modifié avec succès.');
}

function handleProfileAvatar(): void
{
    requireLogin();
    verifyCsrf();

    if (($_POST['remove_avatar'] ?? null) === '1') {
        if (!is_dir(PROFILE_DIR) && !mkdir(PROFILE_DIR, 0700, true) && !is_dir(PROFILE_DIR)) {
            throw new \RuntimeException('Cannot create profile directory.');
        }
        $lock = fopen(PROFILE_AVATAR_LOCK_FILE, 'c');
        if ($lock === false) throw new \RuntimeException('Cannot open profile avatar lock file.');
        try {
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Cannot lock profile avatar.');
            foreach (['jpg', 'png', 'webp'] as $extension) {
                $path = PROFILE_DIR . '/avatar.' . $extension;
                if (is_file($path) && !unlink($path)) {
                    throw new \RuntimeException('Cannot remove profile avatar.');
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        redirect('/?account=profile', 'Photo de profil réinitialisée.');
    }

    $upload = $_FILES['avatar'] ?? null;
    if (!is_array($upload) || !is_int($upload['error'] ?? null) || $upload['error'] !== UPLOAD_ERR_OK) {
        redirect('/?account=profile', 'Sélectionnez une image valide à envoyer.');
    }
    if (!is_string($upload['tmp_name'] ?? null)
        || !is_uploaded_file($upload['tmp_name'])
        || !is_int($upload['size'] ?? null)
        || $upload['size'] < 1
        || $upload['size'] > 5 * 1024 * 1024) {
        redirect('/?account=profile', 'La photo doit faire au maximum 5 Mo.');
    }

    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($upload['tmp_name']);
    $image = getimagesize($upload['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!is_string($mime)
        || !isset($extensions[$mime])
        || !is_array($image)
        || ($image[0] ?? 0) < 1
        || ($image[1] ?? 0) < 1
        || ($image[0] ?? 0) > 5000
        || ($image[1] ?? 0) > 5000
        || ($image[0] ?? 0) * ($image[1] ?? 0) > 25_000_000
        || ($image['mime'] ?? null) !== $mime) {
        redirect('/?account=profile', 'Format non accepté. Utilisez une image JPEG, PNG ou WebP.');
    }

    if (!is_dir(PROFILE_DIR) && !mkdir(PROFILE_DIR, 0700, true) && !is_dir(PROFILE_DIR)) {
        throw new \RuntimeException('Cannot create profile directory.');
    }
    if (!chmod(PROFILE_DIR, 0700)) throw new \RuntimeException('Cannot restrict profile directory permissions.');
    $lock = fopen(PROFILE_AVATAR_LOCK_FILE, 'c');
    if ($lock === false) throw new \RuntimeException('Cannot open profile avatar lock file.');
    try {
        if (!chmod(PROFILE_AVATAR_LOCK_FILE, 0600)) throw new \RuntimeException('Cannot restrict profile avatar lock permissions.');
        if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Cannot lock profile avatar.');
        $extension = $extensions[$mime];
        $temporary = PROFILE_DIR . '/.avatar-' . bin2hex(random_bytes(8));
        if (!move_uploaded_file($upload['tmp_name'], $temporary)) {
            throw new \RuntimeException('Cannot store uploaded profile avatar.');
        }
        if (!chmod($temporary, 0600)) {
            unlink($temporary);
            throw new \RuntimeException('Cannot restrict profile avatar permissions.');
        }
        $destination = PROFILE_DIR . '/avatar.' . $extension;
        if (!rename($temporary, $destination)) {
            unlink($temporary);
            throw new \RuntimeException('Cannot replace profile avatar.');
        }
        foreach (array_diff(['jpg', 'png', 'webp'], [$extension]) as $oldExtension) {
            $oldPath = PROFILE_DIR . '/avatar.' . $oldExtension;
            if (is_file($oldPath) && !unlink($oldPath)) {
                throw new \RuntimeException('Cannot remove previous profile avatar.');
            }
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    redirect('/?account=profile', 'Photo de profil mise à jour.');
}

function handleGetProfileAvatar(): void
{
    requireLogin();
    $avatar = profileAvatarFile();
    if ($avatar === null) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . profileAvatarMimeType($avatar));
    header('Content-Length: ' . filesize($avatar));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline');
    readfile($avatar);
    exit;
}

function handlePeerAvatar(): void
{
    $peer = authenticatedPeer();
    if ($peer === null) peerApiResponse(['error' => 'Forbidden.'], 403);

    $avatar = profileAvatarFile();
    if ($avatar === null) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . profileAvatarMimeType($avatar));
    header('Content-Length: ' . filesize($avatar));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline');
    readfile($avatar);
    exit;
}

function handleGetPeerAvatar(string $username): void
{
    requireLogin();
    $peer = findConfiguredPeer($username);
    if ($peer === null) {
        http_response_code(404);
        exit;
    }

    try {
        $avatar = fetchPeerAvatar($peer);
    } catch (PeerUnavailable $e) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo $e->getMessage();
        exit;
    }
    if ($avatar === null) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $avatar['mime']);
    header('Content-Length: ' . strlen($avatar['body']));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline');
    echo $avatar['body'];
    exit;
}

/**
 * Moves an uploaded file into UPLOADS_DIR/{folder}, auto-renaming on
 * collision. Shared by the session-based /upload and token-based
 * /api/upload handlers, which differ only in auth and response format.
 *
 * @return array{ok: bool, path?: string, error?: string}
 */
function saveUploadedFile(string $folderInput, string $originalFilename, string $tmpName): array
{
    $folder   = sanitizeFolder($folderInput);
    $filename = basename($originalFilename);

    $targetDir = UPLOADS_DIR . ($folder !== '' ? '/' . $folder : '');

    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $targetDir));
        }
    }

    $uploadsReal   = realpath(UPLOADS_DIR);
    $targetDirReal = realpath($targetDir);
    if ($targetDirReal === false || !str_starts_with($targetDirReal, $uploadsReal)) {
        return ['ok' => false, 'error' => 'Invalid folder path.'];
    }

    $filename = autoRename($targetDir, $filename);

    if (!move_uploaded_file($tmpName, $targetDir . '/' . $filename)) {
        return ['ok' => false, 'error' => 'Upload failed.'];
    }

    return ['ok' => true, 'path' => ($folder !== '' ? $folder . '/' : '') . $filename];
}

/**
 * The single verdict on this request's upload, folding the app's own size
 * guard into PHP's UPLOAD_ERR_* codes so both handlers agree on the limit.
 */
function uploadError(): int
{
    $error = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;

    // A body over post_max_size arrives with $_FILES empty and no error set,
    // which otherwise reads as "no file received".
    if ($error === UPLOAD_ERR_NO_FILE && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > phpIniBytes('post_max_size')) {
        return UPLOAD_ERR_INI_SIZE;
    }

    if ($error === UPLOAD_ERR_OK && $_FILES['file']['size'] > effectiveMaxUploadBytes()) {
        return UPLOAD_ERR_FORM_SIZE;
    }

    return $error;
}

function uploadErrorMessage(int $uploadError): string
{
    return match ($uploadError) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large (max ' . formatBytes(effectiveMaxUploadBytes()) . ').',
        UPLOAD_ERR_NO_FILE                        => 'No file received.',
        default                                   => 'Upload error.',
    };
}

function handleUpload(): void
{
    requireLogin();

    // Before the CSRF check: a body over post_max_size empties $_POST too, so
    // the token is missing and "Invalid CSRF token." would mask the real cause.
    $uploadError = uploadError();
    if ($uploadError !== UPLOAD_ERR_OK) {
        redirect('/', uploadErrorMessage($uploadError));
    }

    verifyCsrf();

    $description = $_POST['description'] ?? '';
    if (!is_string($description) || trim($description) === '') {
        redirect('/', 'La description est obligatoire pour uploader un fichier.');
    }

    $result = saveUploadedFile($_POST['folder'] ?? '', $_FILES['file']['name'], $_FILES['file']['tmp_name']);
    if (!$result['ok']) {
        redirect('/', $result['error']);
    }

    $meta   = loadMeta();
    $meta[] = [
        'path'        => $result['path'],
        'description' => trim($description),
        'private'     => !empty($_POST['private']),
        'expires'     => expiryTimestamp($_POST['expiry'] ?? 'never'),
        'uploaded'    => time(),
    ];
    saveMeta($meta);

    redirect('/', 'File uploaded successfully.');
}

function handleApiUpload(): void
{
    header('Content-Type: application/json');

    $token = '';
    if (preg_match('/^Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) {
        $token = $m[1];
    }
    if (API_UPLOAD_SECRET === '' || !hash_equals(API_UPLOAD_SECRET, $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden.']);
        exit;
    }

    $uploadError = uploadError();
    if ($uploadError !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => uploadErrorMessage($uploadError)]
            + (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? ['max_bytes' => effectiveMaxUploadBytes()] : []));
        exit;
    }

    $description = $_POST['description'] ?? '';
    if (!is_string($description) || trim($description) === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Description is required.']);
        exit;
    }

    $result = saveUploadedFile($_POST['folder'] ?? '', $_FILES['file']['name'], $_FILES['file']['tmp_name']);
    if (!$result['ok']) {
        http_response_code(400);
        echo json_encode(['error' => $result['error']]);
        exit;
    }

    $meta   = loadMeta();
    $meta[] = [
        'path'        => $result['path'],
        'description' => trim($description),
        'private'     => !empty($_POST['private']),
        'expires'     => expiryTimestamp($_POST['expiry'] ?? 'never'),
        'uploaded'    => time(),
    ];
    saveMeta($meta);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $result['path'])));
    echo json_encode([
        'path' => $result['path'],
        'url'  => $scheme . '://' . $_SERVER['HTTP_HOST'] . '/download/' . $encodedPath,
    ]);
    exit;
}

function handleDiscover(): void
{
    requireLogin();
    $results = [];

    foreach (configuredPeers() as $peer) {
        $hasUnreadMessages = hasUnreadPeerMessages($peer['username']);
        $files = [];
        $error = null;
        try {
            $response = requestPeerJson($peer, '/api/peer/files');
            if (($response['username'] ?? null) !== $peer['username'] || !is_array($response['files'] ?? null)) {
                throw new PeerUnavailable("La réponse de {$peer['username']} ne correspond pas à sa configuration.");
            }
            foreach ($response['files'] as $file) {
                if (!is_array($file)
                    || !is_string($file['path'] ?? null)
                    || $file['path'] === ''
                    || !is_string($file['description'] ?? null)) {
                    throw new PeerUnavailable("La liste de fichiers de {$peer['username']} est invalide.");
                }
                $files[] = $file;
            }
        } catch (PeerUnavailable $e) {
            $files = [];
            $error = $e->getMessage();
        }

        $messageError = null;
        try {
            peerConversationMessages($peer);
        } catch (PeerUnavailable $e) {
            $messageError = $e->getMessage();
        }

        $results[] = $peer + [
            'files' => $files,
            'error' => $error,
            'has_unread_messages' => $hasUnreadMessages,
            'available' => $messageError === null,
            'message_error' => $messageError,
        ];
    }

    renderDiscover($results);
}

function handleAddPeer(): void
{
    requireLogin();
    verifyCsrf();

    $username = $_POST['username'] ?? null;
    $url = $_POST['url'] ?? null;
    $token = $_POST['token'] ?? null;
    if (!is_string($username) || !is_string($url) || !is_string($token)) {
        redirect('/discover', 'Tous les champs sont obligatoires.');
    }

    try {
        $peer = validatePeerEntries([[
            'username' => trim($username),
            'url' => trim($url),
            'token' => trim($token),
            'dynamic' => true,
        ]])[0];
        updateDynamicPeers(static function (array $peers) use ($peer): array {
            unset($peer['token_id']);
            $peers[] = $peer;
            return $peers;
        });
    } catch (\InvalidArgumentException $e) {
        redirect('/discover', $e->getMessage());
    }

    redirect('/discover', 'Pair ajouté avec succès.');
}

function handleDeletePeer(): void
{
    requireLogin();
    verifyCsrf();

    $username = $_POST['username'] ?? null;
    if (!is_string($username)) redirect('/discover', 'Pair invalide.');

    $removed = false;
    updateDynamicPeers(static function (array $peers) use ($username, &$removed): array {
        $updated = array_values(array_filter($peers, static function ($peer) use ($username, &$removed): bool {
            if (is_array($peer) && ($peer['username'] ?? null) === $username) {
                $removed = true;
                return false;
            }
            return true;
        }));
        return $updated;
    });

    redirect('/discover', $removed ? 'Pair supprimé.' : 'Ce pair ne peut pas être supprimé depuis cette interface.');
}

function handlePeerFiles(): void
{
    $peer = authenticatedPeer();
    if ($peer === null) peerApiResponse(['error' => 'Forbidden.'], 403);

    $meta = loadMeta();
    releaseMetaLock();
    $files = [];
    foreach ($meta as $entry) {
        if (!empty($entry['private']) || (($entry['expires'] ?? null) !== null && $entry['expires'] < time())) continue;
        if (!is_string($entry['path'] ?? null)) continue;
        $files[] = [
            'path' => $entry['path'],
            'description' => is_string($entry['description'] ?? null) ? $entry['description'] : '',
        ];
    }

    peerApiResponse(['username' => APP_USERNAME, 'files' => $files]);
}

function handlePeerMessages(): void
{
    $peer = authenticatedPeer();
    if ($peer === null) peerApiResponse(['error' => 'Forbidden.'], 403);
    peerApiResponse(['messages' => loadPeerMessages($peer['username'])]);
}

function handleReceivePeerMessage(): void
{
    $peer = authenticatedPeer();
    if ($peer === null) peerApiResponse(['error' => 'Forbidden.'], 403);

    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16_384) {
        peerApiResponse(['error' => 'Request body is too large.'], 413);
    }
    $body = file_get_contents('php://input', false, null, 0, 16_385);
    if ($body === false) peerApiResponse(['error' => 'Cannot read request body.'], 400);
    if (strlen($body) > 16_384) peerApiResponse(['error' => 'Request body is too large.'], 413);

    try {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        peerApiResponse(['error' => 'Invalid JSON.'], 400);
    }
    $message = is_array($payload) ? normalizePeerMessage($payload['message'] ?? null) : null;
    $id = is_array($payload) ? ($payload['id'] ?? null) : null;
    if ($message === null || !is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
        peerApiResponse(['error' => 'Message must contain 1 to 2000 characters and a valid identifier.'], 400);
    }

    $timestamp = appendPeerMessage($peer['username'], $id, $peer['username'], $message);
    peerApiResponse(['ok' => true, 'id' => $id, 'timestamp' => $timestamp]);
}

function normalizePeerMessage(mixed $message): ?string
{
    if (!is_string($message) || preg_match('//u', $message) !== 1) return null;
    $message = trim($message);
    if ($message === '' || strlen($message) > 8000 || preg_match_all('/./us', $message) > 2000) return null;
    return $message;
}

function handleMessages(string $username): void
{
    requireLogin();
    $peer = findConfiguredPeer($username);
    if ($peer === null) redirect('/discover', 'Pair non configuré.');

    try {
        $messages = peerConversationMessages($peer);
    } catch (PeerUnavailable $e) {
        redirect('/discover', $e->getMessage());
    }
    markPeerMessagesRead($peer['username'], $messages);
    renderMessages($peer, $messages, null);
}

function handlePollMessages(string $username): void
{
    requireLogin();
    $peer = findConfiguredPeer($username);
    if ($peer === null) peerApiResponse(['error' => 'Peer not configured.'], 404);

    try {
        $messages = peerConversationMessages($peer);
        markPeerMessagesRead($peer['username'], $messages);
        peerApiResponse(['messages' => $messages]);
    } catch (PeerUnavailable $e) {
        peerApiResponse(['error' => $e->getMessage()], 502);
    }
}

function handleUnreadMessages(): void
{
    requireLogin();
    $unread = [];
    foreach (configuredPeers() as $peer) {
        if (hasUnreadPeerMessages($peer['username'])) $unread[] = $peer['username'];
    }
    peerApiResponse(['unread' => $unread]);
}

function peerConversationMessages(array $peer): array
{
    $localMessages = loadPeerMessages($peer['username']);
    $response = requestPeerJson($peer, '/api/peer/messages');
    if (!is_array($response['messages'] ?? null)) {
        throw new PeerUnavailable("L'historique reçu de {$peer['username']} est invalide.");
    }
    try {
        decodePeerMessages(json_encode($response['messages'], JSON_THROW_ON_ERROR));
    } catch (\JsonException|\RuntimeException $e) {
        throw new PeerUnavailable("L'historique reçu de {$peer['username']} est invalide.", 0, $e);
    }
    $messagesById = [];
    foreach (array_merge($localMessages, $response['messages']) as $message) {
        $messagesById[$message['id']] = $message;
    }
    $messages = array_values($messagesById);
    usort($messages, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
    return $messages;
}

function handleSendMessage(string $username): void
{
    requireLogin();
    verifyCsrf();

    $peer = findConfiguredPeer($username);
    if ($peer === null) redirect('/discover', 'Pair non configuré.');

    $message = normalizePeerMessage($_POST['message'] ?? null);
    if ($message === null) {
        redirect('/discover', 'Le message doit contenir entre 1 et 2000 caractères.');
    }

    $id = bin2hex(random_bytes(16));
    try {
        $response = requestPeerJson($peer, '/api/peer/messages', 'POST', ['id' => $id, 'message' => $message]);
        if (($response['ok'] ?? false) !== true
            || ($response['id'] ?? null) !== $id
            || !is_int($response['timestamp'] ?? null)) {
            throw new PeerUnavailable("{$peer['username']} n'a pas confirmé la réception du message.");
        }
        appendPeerMessage($peer['username'], $id, APP_USERNAME, $message, $response['timestamp']);
    } catch (PeerUnavailable $e) {
        redirect('/discover', $e->getMessage());
    }

    redirect('/messages/' . rawurlencode($peer['username']));
}

function peerApiResponse(array $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

function resolveServableFile(string $filePath): string
{
    $filePath = ltrim($filePath, '/');
    $meta     = loadMeta();
    releaseMetaLock();
    $idx      = findIndex($meta, $filePath);

    if ($idx === false) { http_response_code(404); die('File not found.'); }

    $entry = $meta[$idx];

    if ($entry['expires'] !== null && $entry['expires'] < time()) {
        http_response_code(410);
        die('This file has expired.');
    }

    if ($entry['private'] && !isLoggedIn()) {
        http_response_code(403);
        die('This file is private.');
    }

    $uploadsReal = realpath(UPLOADS_DIR);
    $fullPath    = realpath(UPLOADS_DIR . '/' . $filePath);

    if ($uploadsReal === false
        || $fullPath === false
        || !str_starts_with($fullPath, $uploadsReal . DIRECTORY_SEPARATOR)
        || !is_file($fullPath)) {
        http_response_code(404);
        die('File not found.');
    }

    return $fullPath;
}

function isHtmlFile(string $path): bool
{
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['html', 'htm'], true);
}

function handleDownload(string $filePath): void
{
    $fullPath = resolveServableFile($filePath);

    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . (mime_content_type($fullPath) ?: 'application/octet-stream'));
    $filename = basename($fullPath);
    $safeName = preg_replace('/[\x00-\x1f\x7f"\\\\]/', '_', $filename);
    header('Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Length: ' . filesize($fullPath));
    readfile($fullPath);
    exit;
}

function handleView(string $filePath): void
{
    $fullPath = resolveServableFile($filePath);

    if (!isHtmlFile($fullPath)) {
        http_response_code(404);
        die('File not found.');
    }

    // Sandbox without allow-same-origin: page runs in an opaque origin,
    // so uploaded HTML cannot read the session cookie or call the app as us.
    header('Content-Security-Policy: sandbox allow-scripts');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: inline');
    header('Content-Length: ' . filesize($fullPath));
    readfile($fullPath);
    exit;
}

/**
 * Index of $filePath in $meta, or bail out. loadMeta() holds LOCK_EX and only
 * saveMeta() drops it, so a miss has to release the lock itself — and say so,
 * otherwise a no-op looks exactly like a success.
 */
function requireEntry(array $meta, string $filePath): int
{
    $idx = findIndex($meta, $filePath);
    if ($idx === false) {
        releaseMetaLock();
        redirect('/', 'File not found.');
    }
    return $idx;
}

function handleDelete(string $filePath): void
{
    requireLogin();
    verifyCsrf();

    $filePath = ltrim($filePath, '/');
    $meta     = loadMeta();
    $idx      = requireEntry($meta, $filePath);

    $uploadsReal = realpath(UPLOADS_DIR);
    $fullPath    = realpath(UPLOADS_DIR . '/' . $filePath);
    if ($fullPath !== false && str_starts_with($fullPath, $uploadsReal) && is_file($fullPath)) {
        unlink($fullPath);
    }
    array_splice($meta, $idx, 1);
    saveMeta($meta);

    redirect('/');
}

function handleToggle(string $filePath): void
{
    requireLogin();
    verifyCsrf();

    $filePath = ltrim($filePath, '/');
    $meta     = loadMeta();
    $idx      = requireEntry($meta, $filePath);

    $meta[$idx]['private'] = !$meta[$idx]['private'];
    saveMeta($meta);

    redirect('/');
}

function handleExpiry(string $filePath): void
{
    requireLogin();
    verifyCsrf();

    // "Keep" — the select cannot preselect the stored expiry (it is an absolute
    // timestamp matching no preset), so an untouched dropdown means no change.
    if (($_POST['expiry'] ?? '') === '') {
        redirect('/');
    }

    $filePath = ltrim($filePath, '/');
    $meta     = loadMeta();
    $idx      = requireEntry($meta, $filePath);

    $meta[$idx]['expires'] = expiryTimestamp($_POST['expiry']);
    saveMeta($meta);

    redirect('/');
}

function handleCron(): void
{
    // Header is preferred: query strings end up in access logs and crontabs.
    $given = $_SERVER['HTTP_X_CRON_SECRET'] ?? $_GET['secret'] ?? '';

    if (CRON_SECRET === '' || !hash_equals(CRON_SECRET, $given)) {
        http_response_code(403);
        die('Forbidden.');
    }

    $meta    = loadMeta();
    $now     = time();
    $removed = 0;

    foreach ($meta as $entry) {
        if ($entry['expires'] !== null && $entry['expires'] < $now) {
            $uploadsReal = realpath(UPLOADS_DIR);
            $fullPath    = realpath(UPLOADS_DIR . '/' . $entry['path']);
            if ($fullPath !== false && str_starts_with($fullPath, $uploadsReal) && is_file($fullPath)) {
                unlink($fullPath);
            }
            $removed++;
        }
    }

    $meta = array_filter($meta, fn($e) => $e['expires'] === null || $e['expires'] >= $now);
    saveMeta($meta);

    header('Content-Type: text/plain');
    echo "Removed $removed expired file(s).\n";
    exit;
}

function notFound(): void
{
    http_response_code(404);
    echo '404 Not Found';
}

// --- Views ---

function renderLogin(?string $error = null): void
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    include __DIR__ . '/views/login.php';
}

function renderDashboard(array $meta): void
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    // Group by folder (dirname), root files keyed as ''
    $folders = [];
    foreach ($meta as $entry) {
        $dir = dirname($entry['path']);
        $folders[$dir === '.' ? '' : $dir][] = $entry;
    }
    ksort($folders);

    include __DIR__ . '/views/dashboard.php';
}

function renderDiscover(array $peers): void
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    include __DIR__ . '/views/discover.php';
}

function renderMessages(array $peer, array $messages, ?string $error): void
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    include __DIR__ . '/views/messages.php';
}
