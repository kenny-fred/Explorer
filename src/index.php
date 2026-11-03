<?php
declare(strict_types=1);

define('ROOT',                 dirname(__DIR__));
define('UPLOADS_DIR',          ROOT . '/uploads');
define('DATA_DIR',             ROOT . '/data');
define('DATA_FILE',            ROOT . '/data/files.json');
define('LOCK_FILE',            ROOT . '/data/.lock');
define('PASSWORD_FILE',         ROOT . '/data/password.json');
define('PASSWORD_LOCK_FILE',    ROOT . '/data/.password.lock');
define('PROFILE_DIR',           DATA_DIR . '/profile');
define('PROFILE_AVATAR_LOCK_FILE', PROFILE_DIR . '/.avatar.lock');
define('ENV_FILE',              ROOT . '/.env');
define('SESSION_IDLE_SECONDS', 3600);

require __DIR__ . '/helpers.php';
require __DIR__ . '/handlers.php';

// --- Bootstrap ---

$env = loadEnv();
define('APP_USERNAME',      $env['USERNAME']      ?? '');
define('APP_PASSWORD',      loadPasswordHash($env['PASSWORD'] ?? ''));
define('CRON_SECRET',       $env['CRON_SECRET']   ?? '');
define('API_UPLOAD_SECRET', $env['API_UPLOAD_SECRET'] ?? '');
define('PEERS_JSON',        $env['PEERS_JSON']    ?? '[]');
define('MAX_UPLOAD_BYTES',  (int)($env['MAX_UPLOAD_MB'] ?? 3072) * 1024 * 1024);

define('CSP_NONCE', base64_encode(random_bytes(16)));

session_set_cookie_params([
    'secure' => isHttpsRequest(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'nonce-" . CSP_NONCE . "'; frame-ancestors 'none'");
header('Referrer-Policy: same-origin');

if (!empty($_SESSION['logged_in'])) {
    if (isset($_SESSION['last_active']) && (time() - $_SESSION['last_active']) > SESSION_IDLE_SECONDS) {
        session_unset();
        session_destroy();
        clearSessionCookie();
        header('Location: /');
        exit;
    }
    $_SESSION['last_active'] = time();
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// --- Router ---

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path   = rtrim($uri, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

match (true) {
    $path === '/'                                              => handleDashboard(),
    $path === '/login'  && $method === 'POST'                 => handleLogin(),
    $path === '/logout'                                       => handleLogout(),
    $path === '/upload' && $method === 'POST'                 => handleUpload(),
    $path === '/api/upload' && $method === 'POST'              => handleApiUpload(),
    $path === '/discover' && $method === 'GET'                 => handleDiscover(),
    $path === '/peers/add' && $method === 'POST'               => handleAddPeer(),
    $path === '/peers/delete' && $method === 'POST'            => handleDeletePeer(),
    $path === '/account/password' && $method === 'POST'        => handleChangePassword(),
    $path === '/account/avatar' && $method === 'POST'           => handleProfileAvatar(),
    $path === '/account/avatar' && $method === 'GET'            => handleGetProfileAvatar(),
    $path === '/api/peer/avatar' && $method === 'GET'           => handlePeerAvatar(),
    str_starts_with($path, '/peer-avatar/') && $method === 'GET' => handleGetPeerAvatar(rawurldecode(substr($path, 13))),
    str_starts_with($path, '/messages/') && $method === 'GET'  => handleMessages(rawurldecode(substr($path, 10))),
    str_starts_with($path, '/messages/') && $method === 'POST' => handleSendMessage(rawurldecode(substr($path, 10))),
    $path === '/api/peer/files' && $method === 'GET'            => handlePeerFiles(),
    $path === '/api/peer/messages' && $method === 'GET'         => handlePeerMessages(),
    $path === '/api/peer/messages' && $method === 'POST'        => handleReceivePeerMessage(),
    $path === '/api/messages/unread' && $method === 'GET' => handleUnreadMessages(),
    str_starts_with($path, '/api/messages/') && $method === 'GET' => handlePollMessages(rawurldecode(substr($path, 14))),
    str_starts_with($path, '/download/')                      => handleDownload(rawurldecode(substr($path, 10))),
    str_starts_with($path, '/view/')                          => handleView(rawurldecode(substr($path, 6))),
    str_starts_with($path, '/delete/')  && $method === 'POST' => handleDelete(rawurldecode(substr($path, 8))),
    str_starts_with($path, '/toggle/')  && $method === 'POST' => handleToggle(rawurldecode(substr($path, 8))),
    str_starts_with($path, '/expiry/')  && $method === 'POST' => handleExpiry(rawurldecode(substr($path, 8))),
    $path === '/cron'                                         => handleCron(),
    default                                                   => notFound(),
};
