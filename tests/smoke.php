<?php
/**
 * Smoke test. Run with: php tests/smoke.php
 *
 * Boots the app on a throwaway copy of the tree so the real .env, uploads/
 * and data/ are never touched, then exercises the cron endpoint over HTTP.
 */
declare(strict_types=1);

$failures = 0;

function check(string $what, mixed $expected, mixed $actual): void
{
    global $failures;
    if ($expected === $actual) {
        echo "  ok   $what\n";
        return;
    }
    $failures++;
    printf("  FAIL %s\n       expected: %s\n       actual:   %s\n", $what, var_export($expected, true), var_export($actual, true));
}

// --- Throwaway copy of the app ---

$root   = dirname(__DIR__);
$tmp    = sys_get_temp_dir() . '/Explorer-smoke-' . getmypid();
$secret = 'test-secret-' . bin2hex(random_bytes(8));

register_shutdown_function(static function () use ($tmp) {
    exec('rm -rf ' . escapeshellarg($tmp));
});

mkdir($tmp . '/data', 0777, true);
mkdir($tmp . '/uploads', 0777, true);
exec(sprintf('cp -r %s %s', escapeshellarg($root . '/src'), escapeshellarg($tmp . '/src')));
if (!symlink($tmp . '/uploads', $tmp . '/src/uploads')) {
    throw new RuntimeException('Cannot create upload symlink for direct-access security test.');
}
$apiSecret = 'api-secret-' . bin2hex(random_bytes(8));
$password  = 'pw-' . bin2hex(random_bytes(8));
$hash      = password_hash($password, PASSWORD_DEFAULT);
file_put_contents($tmp . '/.env', "USERNAME=test\nPASSWORD=$hash\nCRON_SECRET=$secret\nAPI_UPLOAD_SECRET=$apiSecret\nMAX_UPLOAD_MB=3072\n");

// An already-expired file, so cron has something real to delete. Every cron
// request consumes this, so re-seed before any assertion that depends on it.
$seed = static function () use ($tmp) {
    file_put_contents($tmp . '/uploads/expired.txt', 'bye');
    file_put_contents($tmp . '/uploads/kept.txt', 'stay');
    file_put_contents($tmp . '/data/files.json', json_encode([
        ['path' => 'expired.txt', 'private' => false, 'expires' => time() - 3600, 'uploaded' => time()],
        ['path' => 'kept.txt',    'private' => false, 'expires' => null,          'uploaded' => time()],
    ]));
};
$seed();

// --- Boot the built-in server on a free port ---

/** @param array<string,string> $ini php.ini overrides for this server */
function boot(string $tmp, array $ini = []): string
{
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port  = (int)explode(':', stream_socket_get_name($probe, false))[1];
    fclose($probe);

    $flags = '';
    foreach ($ini as $k => $v) {
        $flags .= ' -d ' . escapeshellarg("$k=$v");
    }

    $server = proc_open(
        sprintf('exec php%s -S 127.0.0.1:%d -t %s %s', $flags, $port, escapeshellarg($tmp . '/src'), escapeshellarg($tmp . '/src/router.php')),
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes
    );
    register_shutdown_function(static fn() => proc_terminate($server));

    for ($i = 0; $i < 100; $i++) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($probe) { fclose($probe); break; }
        usleep(50_000);
    }

    return "http://127.0.0.1:$port";
}

/** @return array{0:int,1:string} status code and body */
function get(string $url, array $headers = []): array
{
    return request($url, ['header' => $headers]);
}

/** Multipart POST of a single in-memory file. @return array{0:int,1:string} */
function postFile(
    string $url,
    string $filename,
    string $contents,
    array $headers = [],
    array $fields = [],
    string $fileFieldName = 'file'
): array
{
    $boundary = '----smoke' . bin2hex(random_bytes(8));
    $body = '';

    foreach ($fields as $name => $value) {
        $body .= "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"$name\"\r\n\r\n"
            . $value . "\r\n";
    }
    $body .= "--$boundary\r\n"
        . "Content-Disposition: form-data; name=\"$fileFieldName\"; filename=\"$filename\"\r\n"
        . "Content-Type: application/octet-stream\r\n\r\n"
        . $contents . "\r\n--$boundary--\r\n";

    return request($url, [
        'method'  => 'POST',
        'header'  => array_merge($headers, ["Content-Type: multipart/form-data; boundary=$boundary"]),
        'content' => $body,
    ]);
}

/** @return array{0:int,1:string,2:string[]} status code, body, response headers */
function request(string $url, array $http): array
{
    $body = @file_get_contents($url, false, stream_context_create([
        'http' => $http + ['ignore_errors' => true, 'follow_location' => 0],
    ])) ?: '';
    $headers = $http_response_header ?? [];
    preg_match('{ (\d{3}) }', $headers[0] ?? '', $m);
    return [(int)($m[1] ?? 0), $body, $headers];
}

/**
 * Logged-in browser: replays the session cookie and the CSRF token, so the
 * dashboard's own forms can be exercised as a user submits them.
 */
final class Browser
{
    private string $cookie = '';
    private string $csrf   = '';
    private array $responseHeaders = [];
    private string $sessionCookieHeader = '';

    public function __construct(private string $base) {}

    public function login(string $user, string $password): void
    {
        $this->get('/');
        $this->post('/login', ['username' => $user, 'password' => $password]);
        $this->get('/');
    }

    /** @return array{0:int,1:string} */
    public function get(string $path): array
    {
        [$code, $body, $headers] = request($this->base . $path, ['header' => $this->headers()]);
        $this->absorb($headers, $body);
        return [$code, $body];
    }

    public function responseHeaders(): array
    {
        return $this->responseHeaders;
    }

    public function sessionCookieHeader(): string
    {
        return $this->sessionCookieHeader;
    }

    /** @return array{0:int,1:string} */
    public function post(string $path, array $fields): array
    {
        [$code, $body, $headers] = request($this->base . $path, [
            'method'  => 'POST',
            'header'  => array_merge($this->headers(), ['Content-Type: application/x-www-form-urlencoded']),
            'content' => http_build_query($fields + ['csrf' => $this->csrf]),
        ]);
        $this->absorb($headers, $body);
        return [$code, $body];
    }

    /** @return array{0:int,1:string} */
    public function upload(string $path, string $filename, string $contents, array $fields = [], string $fileFieldName = 'file'): array
    {
        [$code, $body, $headers] = postFile(
            $this->base . $path,
            $filename,
            $contents,
            $this->headers(),
            $fields + ['csrf' => $this->csrf],
            $fileFieldName
        );
        $this->absorb($headers, $body);
        return [$code, $body];
    }

    private function headers(): array
    {
        return $this->cookie === '' ? [] : ["Cookie: {$this->cookie}"];
    }

    private function absorb(array $headers, string $body): void
    {
        $this->responseHeaders = $headers;
        foreach ($headers as $header) {
            if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $m)) {
                $this->cookie = $m[1];
                $this->sessionCookieHeader = $header;
            }
        }
        if (preg_match('/name="csrf" value="([^"]+)"/', $body, $m)) $this->csrf = $m[1];
    }
}

$base = boot($tmp);
$largeUploadConfig = boot($tmp, ['upload_max_filesize' => '4G', 'post_max_size' => '4G']);
$largeUploadBrowser = new Browser($largeUploadConfig);
$largeUploadBrowser->login('test', $password);
check('configured upload limit is 3 GiB when PHP limits allow it', true, str_contains(
    $largeUploadBrowser->get('/')[1],
    'max 3 GB'
));

// --- Cron authentication ---

echo "cron auth\n";
check('header with the right secret is accepted', 200, get("$base/cron", ["X-Cron-Secret: $secret"])[0]);
check('query parameter still accepted (legacy callers)', 200, get("$base/cron?secret=$secret")[0]);
check('wrong header secret is rejected', 403, get("$base/cron", ['X-Cron-Secret: wrong'])[0]);
check('wrong query secret is rejected', 403, get("$base/cron?secret=wrong")[0]);
check('no secret at all is rejected', 403, get("$base/cron")[0]);

// --- Cron actually expires files ---

echo "cron cleanup\n";
$seed();
[, $body] = get("$base/cron?secret=$secret");
check('reports one removal', true, str_contains($body, 'Removed 1 expired file(s).'));
check('expired file is deleted', false, file_exists($tmp . '/uploads/expired.txt'));
check('unexpired file is kept', true, file_exists($tmp . '/uploads/kept.txt'));
check('expired entry dropped from metadata', ['kept.txt'], array_column(
    json_decode(file_get_contents($tmp . '/data/files.json'), true),
    'path'
));

// --- Secrets must not leak into a rejection response ---

echo "no secret disclosure\n";
[, $forbidden] = get("$base/cron", ['X-Cron-Secret: wrong']);
check('403 body does not echo the real secret', false, str_contains($forbidden, $secret));

// --- Upload limit is the php.ini-clamped one, not MAX_UPLOAD_MB ---
//
// .env says 3 GiB, php.ini says 1 MB, so 1 MB is the truth the API must report.

echo "upload size limit\n";
// display_errors off, as in production: PHP's own "POST Content-Length exceeds
// the limit" warning would otherwise flush headers before the handler replies.
$clamped = boot($tmp, ['upload_max_filesize' => '1M', 'post_max_size' => '2M', 'display_errors' => '0']);
$auth    = ["Authorization: Bearer $apiSecret"];

[$code, $body] = postFile("$clamped/api/upload", 'big.bin', str_repeat('x', 1_500_000), $auth);
$json = json_decode($body, true);
check('over upload_max_filesize is rejected', 400, $code);
check('error names the effective limit', true, str_contains((string)($json['error'] ?? ''), '1 MB'));
check('max_bytes is the clamped limit, not MAX_UPLOAD_MB', 1024 * 1024, $json['max_bytes'] ?? null);

[$code, $body] = postFile("$clamped/api/upload", 'huge.bin', str_repeat('x', 3_000_000), $auth);
$json = json_decode($body, true);
check('over post_max_size is rejected', 400, $code);
check('over post_max_size is not reported as a missing file', false, str_contains((string)($json['error'] ?? ''), 'No file received'));

[$code, $body] = postFile("$clamped/api/upload", 'ok.bin', str_repeat('x', 1_000), $auth, ['description' => 'API upload test']);
check('a file under the limit still uploads', 200, $code);
check('upload lands on disk', true, file_exists($tmp . '/uploads/ok.bin'));
$apiUnicodeName = 'Pourquoi lIA tourne sur des GPU et pas des CPU.mp4';
[$code, $body] = postFile("$clamped/api/upload", $apiUnicodeName, 'api video', $auth, [
    'description' => 'Unicode API upload',
    'folder' => 'Vidéos API',
]);
$apiUnicode = json_decode($body, true);
check('API upload preserves accented folders', 'Vidéos API/' . $apiUnicodeName, $apiUnicode['path'] ?? null);
check('API upload returns a percent-encoded download URL', true,
    str_contains((string)($apiUnicode['url'] ?? ''), '/download/Vid%C3%A9os%20API/Pourquoi%20lIA%20tourne%20sur%20des%20GPU%20et%20pas%20des%20CPU.mp4'));
[$code, $body] = get($apiUnicode['url'] ?? '');
check('API upload URL downloads its Unicode path', 200, $code);
check('API uploaded Unicode file contents are returned', 'api video', $body);
$apiEntry = array_values(array_filter(
    json_decode(file_get_contents($tmp . '/data/files.json'), true),
    fn($entry) => $entry['path'] === 'ok.bin'
))[0] ?? null;
check('API upload stores its description', 'API upload test', $apiEntry['description'] ?? null);
[$code, $body] = postFile("$clamped/api/upload", 'missing-description.bin', 'x', $auth);
check('API upload rejects a missing description', 400, $code);
check('API error explains the required description', 'Description is required.', json_decode($body, true)['error'] ?? null);

check('missing file is still reported as such', true, str_contains(
    postFile("$clamped/api/upload", '', '', $auth)[1],
    'No file received'
));
check('bad token is still rejected', 403, postFile("$clamped/api/upload", 'x.bin', 'x', ['Authorization: Bearer wrong'])[0]);

// --- Dashboard row actions ---
//
// The expiry select cannot preselect a stored absolute timestamp, so its
// default must be a no-op. It used to default to "Never", which meant
// submitting the row untouched silently cleared the file's expiry.

echo "dashboard row actions\n";
$seed();
// By path, and no ?? — a stored null is a real value here, not a missing one.
$expiry = static function () use ($tmp) {
    foreach (json_decode(file_get_contents($tmp . '/data/files.json'), true) as $entry) {
        if ($entry['path'] === 'kept.txt') return $entry['expires'];
    }
    return 'missing';
};

$browser = new Browser($base);
$browser->login('test', $password);
$sessionCookie = $browser->sessionCookieHeader();
check('HTTP session cookie is not marked Secure', false, str_contains($sessionCookie, 'Secure'));
check('HTTP session cookie remains HttpOnly', true, str_contains($sessionCookie, 'HttpOnly'));
check('HTTP session cookie keeps SameSite=Lax', true, str_contains($sessionCookie, 'SameSite=Lax'));
$forwardedHttps = get($base . '/', ['X-Forwarded-Proto: https'])[2];
$forwardedCookie = implode('; ', array_filter($forwardedHttps, fn($header) => stripos($header, 'Set-Cookie:') === 0));
check('untrusted X-Forwarded-Proto does not mark the HTTP cookie Secure', false, str_contains($forwardedCookie, 'Secure'));
$savedDashboardMeta = file_get_contents($tmp . '/data/files.json');
file_put_contents($tmp . '/data/files.json', '[]');
$emptyDashboard = $browser->get('/')[1];
check('empty state appears in the left-side file list', true,
    str_contains($emptyDashboard, '<section class="dashboard-files">') && str_contains($emptyDashboard, 'Aucun fichier pour le moment.'));
file_put_contents($tmp . '/data/files.json', $savedDashboardMeta);
$dashboard = $browser->get('/')[1];
check('login lands on the dashboard', true, str_contains($dashboard, 'Nouveau fichier'));
check('dashboard removes the right-side profile navigation', false, str_contains($dashboard, 'class="account-menu"'));
check('dashboard toolbar places upload and discovery above the file list', true,
    strpos($dashboard, 'class="dashboard-toolbar"') < strpos($dashboard, 'class="dashboard-files"')
        && str_contains($dashboard, 'id="btn-toggle-upload"')
        && str_contains($dashboard, 'class="dashboard-discover-button"'));
[$code, $profilePage] = $browser->get('/discover');
check('profile menu remains available on discovery', true, str_contains($profilePage, 'class="account-menu"'));
check('account profile shows the requested host and port', true,
    str_contains($profilePage, parse_url($base, PHP_URL_HOST) . ':' . parse_url($base, PHP_URL_PORT)));
check('password change is offered as a modal action', true,
    str_contains($profilePage, 'data-open-password-dialog') && str_contains($profilePage, '<dialog class="account-dialog"'));
check('password modal opens after a validation redirect', true,
    str_contains($browser->get('/discover?account=password')[1], 'passwordDialog.showModal()'));
check('profile menu offers an image upload', true,
    str_contains($profilePage, 'enctype="multipart/form-data"') && str_contains($profilePage, 'name="avatar"'));
check('file list is positioned before the upload form', true,
    strpos($dashboard, 'dashboard-files') < strpos($dashboard, 'dashboard-upload'));
check('upload form opens the two-column dashboard layout', true,
    str_contains($dashboard, "classList.toggle('upload-open'"));
[$code] = get("$base/account/avatar");
check('profile avatar endpoint requires authentication', 302, $code);
$pngAvatar = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lfgAAAAASUVORK5CYII=', true);
[$code] = $browser->upload('/account/avatar', 'profile.png', $pngAvatar, [], 'avatar');
check('profile avatar image upload succeeds', 302, $code);
$avatarUploadFeedback = $browser->get('/discover?account=profile')[1];
check('profile avatar upload reports success', true, str_contains($avatarUploadFeedback, 'Photo de profil mise à jour.'));
check('profile avatar is stored privately', true, file_exists($tmp . '/data/profile/avatar.png'));
check('profile avatar file permissions are private', 0600, fileperms($tmp . '/data/profile/avatar.png') & 0777);
check('profile image appears in account menu', true, str_contains($browser->get('/discover')[1], 'src="/account/avatar?v='));
[$code, $avatarContents] = $browser->get('/account/avatar');
check('authenticated profile avatar endpoint serves the image', 200, $code);
check('profile avatar bytes are served intact', $pngAvatar, $avatarContents);
$badSvg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
[$code] = $browser->upload('/account/avatar', 'profile.svg', $badSvg, [], 'avatar');
check('profile avatar rejects SVG uploads', 302, $code);
check('rejected avatar upload preserves the current profile image', true, file_exists($tmp . '/data/profile/avatar.png'));
[$code] = $browser->post('/account/avatar', ['remove_avatar' => '1']);
check('profile avatar can be reset to the default icon', 302, $code);
check('reset removes private avatar file', false, file_exists($tmp . '/data/profile/avatar.png'));
check('reset restores the default account icon', true,
    str_contains($browser->get('/discover')[1], '<svg class="account-avatar"'));

[$code] = $browser->upload('/upload', 'no-description.txt', 'x', ['description' => '   ']);
check('dashboard upload rejects a blank description', 302, $code);
check('rejected upload does not create its file', false, file_exists($tmp . '/uploads/no-description.txt'));
check('dashboard explains that a description is required', true, str_contains($browser->get('/')[1], 'La description est obligatoire'));

[$code] = $browser->upload('/upload', 'described.txt', 'contents', ['description' => 'A useful description']);
check('dashboard upload accepts a description', 302, $code);
$uploadedMeta = json_decode(file_get_contents($tmp . '/data/files.json'), true);
$describedEntry = array_values(array_filter($uploadedMeta, fn($entry) => $entry['path'] === 'described.txt'))[0] ?? null;
check('dashboard upload stores the trimmed description', 'A useful description', $describedEntry['description'] ?? null);
[$code, $body] = $browser->get('/');
check('dashboard displays the description in a styled box', true,
    str_contains($body, 'class="file-description"') && str_contains($body, 'A useful description'));
$browser->upload('/upload', 'escaped.txt', 'contents', ['description' => '<script>alert(1)</script>']);
check('dashboard escapes description HTML', true, str_contains($browser->get('/')[1], '&lt;script&gt;'));

[$code] = $browser->upload('/upload', 'Pourquoi lIA tourne sur des GPU et pas des CPU.mp4', 'video', [
    'folder' => 'Vidéos',
    'description' => 'Fichier avec accents et espaces',
]);
$unicodePath = 'Vidéos/Pourquoi lIA tourne sur des GPU et pas des CPU.mp4';
check('upload preserves accents in folder names', true, file_exists($tmp . '/uploads/' . $unicodePath));
$encodedPath = implode('/', array_map('rawurlencode', explode('/', $unicodePath)));
[$code, $body, $headers] = request($base . '/download/' . $encodedPath, []);
check('download resolves encoded accents and spaces', 200, $code);
check('download returns the requested file contents', 'video', $body);
check('download response includes a UTF-8 filename', true,
    str_contains(implode("\n", $headers), "filename*=UTF-8''Pourquoi%20lIA%20tourne%20sur%20des%20GPU%20et%20pas%20des%20CPU.mp4"));
[$code, $body] = $browser->get('/');
check('dashboard encodes Unicode file paths in links', true, str_contains($body, '/download/Vid%C3%A9os/Pourquoi%20lIA%20tourne%20sur%20des%20GPU%20et%20pas%20des%20CPU.mp4'));

$browser->post('/expiry/kept.txt', ['expiry' => '7d']);
$sevenDays = $expiry();
check('an explicit expiry is stored', true, is_int($sevenDays) && abs($sevenDays - (time() + 86_400 * 7)) < 60);

$browser->post('/expiry/kept.txt', ['expiry' => '']);
check('submitting the untouched dropdown keeps the expiry', $sevenDays, $expiry());

$browser->post('/expiry/kept.txt', ['expiry' => 'never']);
check('choosing Never still clears it', null, $expiry());

check('a missing file reports itself instead of failing silently', true, str_contains(
    $browser->post('/expiry/ghost.txt', ['expiry' => '1h'])[1] . $browser->get('/')[1],
    'File not found.'
));
check('a missing file did not release the lock into a wedged state', true, str_contains(
    $browser->get('/')[1],
    'kept.txt'
));

$browser->post('/toggle/kept.txt', []);
check('toggle still flips private', true, json_decode(file_get_contents($tmp . '/data/files.json'), true)[1]['private']);

$browser->post('/delete/kept.txt', []);
check('delete still removes the file', false, file_exists($tmp . '/uploads/kept.txt'));
check('delete still removes the metadata entry', false, in_array('kept.txt', array_column(
    json_decode(file_get_contents($tmp . '/data/files.json'), true),
    'path'
), true));

// --- Explicitly configured peer discovery and messaging ---

echo "configured peers\n";
$remote = $tmp . '/remote';
mkdir($remote . '/data', 0777, true);
mkdir($remote . '/uploads', 0777, true);
exec(sprintf('cp -r %s %s', escapeshellarg($root . '/src'), escapeshellarg($remote . '/src')));

$peerPassword = 'peer-' . bin2hex(random_bytes(8));
$peerHash = password_hash($peerPassword, PASSWORD_DEFAULT);
$peerToken = bin2hex(random_bytes(32));
file_put_contents($remote . '/.env', "USERNAME=alice\nPASSWORD=$peerHash\nCRON_SECRET=$secret\nMAX_UPLOAD_MB=3072\n");
$remoteBase = boot($remote);

$localPeers = json_encode(
    [['username' => 'alice', 'url' => $remoteBase, 'token' => $peerToken]],
    JSON_THROW_ON_ERROR
);
$remotePeers = json_encode(
    [['username' => 'test', 'url' => $base, 'token' => $peerToken]],
    JSON_THROW_ON_ERROR
);
file_put_contents($tmp . '/.env', "USERNAME=test\nPASSWORD=$hash\nCRON_SECRET=$secret\nAPI_UPLOAD_SECRET=$apiSecret\nMAX_UPLOAD_MB=3072\nPEERS_JSON=$localPeers\n");
file_put_contents($remote . '/.env', "USERNAME=alice\nPASSWORD=$peerHash\nCRON_SECRET=$secret\nMAX_UPLOAD_MB=3072\nPEERS_JSON=$remotePeers\n");

file_put_contents($tmp . '/uploads/local-public.txt', 'public');
file_put_contents($tmp . '/uploads/local-private.txt', 'private');
file_put_contents($tmp . '/data/files.json', json_encode([
    ['path' => 'local-public.txt', 'description' => 'Visible locally', 'private' => false, 'expires' => null, 'uploaded' => time()],
    ['path' => 'local-private.txt', 'description' => 'Hidden locally', 'private' => true, 'expires' => null, 'uploaded' => time()],
]));
check('direct static access to uploaded files is blocked', 403, get("$base/uploads/local-private.txt")[0]);
check('anonymous request cannot download a private file URL', 403, get("$base/download/local-private.txt")[0]);
check('authenticated owner can still download a private file', 'private', $browser->get('/download/local-private.txt')[1]);
check('public download remains available without a session', 'public', get("$base/download/local-public.txt")[1]);
file_put_contents($remote . '/uploads/remote-public.txt', 'public');
file_put_contents($remote . '/uploads/remote-private.txt', 'private');
file_put_contents($remote . '/data/files.json', json_encode([
    ['path' => 'remote-public.txt', 'description' => 'Visible remotely', 'private' => false, 'expires' => null, 'uploaded' => time()],
    ['path' => 'remote-private.txt', 'description' => 'Hidden remotely', 'private' => true, 'expires' => null, 'uploaded' => time()],
]));
mkdir($remote . '/data/profile', 0700, true);
file_put_contents($remote . '/data/profile/avatar.png', $pngAvatar);

check('peer API rejects requests without a token', 403, get("$remoteBase/api/peer/files")[0]);
check('peer avatar API rejects requests without a token', 403, get("$remoteBase/api/peer/avatar")[0]);
[$code, $peerAvatarBody, $peerAvatarHeaders] = request("$remoteBase/api/peer/avatar", [
    'header' => ["Authorization: Bearer $peerToken"],
]);
check('peer avatar API serves the image to a configured peer', 200, $code);
check('peer avatar API returns the image content type', true,
    str_contains(implode("\n", $peerAvatarHeaders), 'Content-Type: image/png'));
check('peer avatar API returns the configured profile image', $pngAvatar, $peerAvatarBody);
check('peer avatar proxy requires a logged-in user', 302, get("$base/peer-avatar/alice")[0]);
[$code, $proxiedAvatar] = $browser->get('/peer-avatar/alice');
$proxiedHeaders = $browser->responseHeaders();
check('authenticated peer avatar proxy serves the contact image', 200, $code);
check('peer avatar proxy returns an image', true, str_contains(implode("\n", $proxiedHeaders), 'Content-Type: image/png'));
check('peer avatar proxy preserves the image bytes', $pngAvatar, $proxiedAvatar);
[$code, $body] = $browser->get('/discover');
check('discovery lists a configured peer', true, str_contains($body, 'alice'));
check('discovery includes the account profile menu', true, str_contains($body, 'class="account-menu"'));
check('peer card aligns message button beside the profile', true,
    str_contains($body, 'class="peer-card-header"') && str_contains($body, 'class="peer-avatar"') && str_contains($body, 'class="peer-message-button"'));
check('peer cards include a public-folder icon', true, str_contains($body, 'class="peer-folder-heading"'));
check('discovery shows the peer public file', true, str_contains($body, 'remote-public.txt'));
check('discovery displays peer file descriptions in a styled box', true, str_contains($body, 'peer-file-description'));
check('discovery hides the peer private file', false, str_contains($body, 'remote-private.txt'));
check('discovery renders the message action as a button', true, str_contains($body, 'class="peer-message-button"'));
check('public file list has spacing below the message button', true, str_contains($body, 'class="peer-public-files"'));

$wrongRemotePeers = json_encode(
    [['username' => 'test', 'url' => $base, 'token' => bin2hex(random_bytes(32))]],
    JSON_THROW_ON_ERROR
);
file_put_contents($remote . '/.env', "USERNAME=alice\nPASSWORD=$peerHash\nCRON_SECRET=$secret\nMAX_UPLOAD_MB=3072\nPEERS_JSON=$wrongRemotePeers\n");
[, $body] = $browser->get('/discover');
check('discovery explains a reciprocal token mismatch', true,
    str_contains($body, 'a refusé le token (HTTP 403)') && str_contains($body, 'même token partagé'));
file_put_contents($remote . '/.env', "USERNAME=alice\nPASSWORD=$peerHash\nCRON_SECRET=$secret\nMAX_UPLOAD_MB=3072\nPEERS_JSON=$remotePeers\n");

[$code, $body] = get("$base/api/peer/files", ["Authorization: Bearer $peerToken"]);
$localListing = json_decode($body, true);
check('peer API authenticates a configured token', 200, $code);
check('peer API exposes public local files', true, in_array('local-public.txt', array_column($localListing['files'] ?? [], 'path'), true));
check('peer API excludes private local files', false, in_array('local-private.txt', array_column($localListing['files'] ?? [], 'path'), true));
check('peer API rejects a wrong token', 403, get("$base/api/peer/files", ['Authorization: Bearer wrong'])[0]);

$browser->post('/messages/alice', ['message' => 'Hello Alice']);
[, $body] = $browser->get('/messages/alice');
check('sent message appears in the local conversation', true, str_contains($body, 'Hello Alice'));
check('message page script keeps its CSP nonce', true, (bool)preg_match('/<script nonce="[^"]+">/', $body));
$localHistory = json_decode(file_get_contents($tmp . '/data/messages/' . hash('sha256', 'alice') . '.json'), true);
check('sent messages are stored locally', true, in_array('Hello Alice', array_column($localHistory, 'message'), true));
[$code, $body] = $browser->get('/api/messages/alice');
check('message polling returns the current conversation', 200, $code);
check('message polling includes the sent message', true, str_contains($body, 'Hello Alice'));

$remoteBrowser = new Browser($remoteBase);
$remoteBrowser->login('alice', $peerPassword);
[, $body] = $remoteBrowser->get('/messages/test');
check('the configured peer receives the message', true, str_contains($body, 'Hello Alice'));
$remoteBrowser->post('/messages/test', ['message' => 'Hello back']);
[, $body] = $browser->get('/discover');
check('discovery marks a new received message as unread', true, str_contains($body, 'class="peer-unread-dot"'));
[$code, $body] = $browser->get('/api/messages/unread');
check('unread message polling returns the peer', 200, $code);
check('unread message polling identifies the correspondent', true, in_array('alice', json_decode($body, true)['unread'] ?? [], true));
[, $body] = $browser->get('/messages/alice');
check('messages are visible in both directions', true, str_contains($body, 'Hello back'));
check('conversation header loads peer profile photo at the right-side nav', true,
    str_contains($body, '<nav class="app-nav conversation-nav">') && str_contains($body, 'class="conversation-avatar" src="/peer-avatar/alice"'));
check('conversation header includes a hidden default avatar fallback', true,
    str_contains($body, 'class="conversation-avatar-fallback" aria-hidden="true" hidden'));
check('message page hides the local account profile menu', false, str_contains($body, 'class="account-menu"'));
check('message page includes a return button to discovery', true,
    str_contains($body, 'class="messages-back-button" href="/discover"'));
check('incoming messages use the left chat alignment', true, str_contains($body, 'message-incoming'));
check('outgoing messages use the right chat alignment', true, str_contains($body, 'message-outgoing'));
check('conversation page uses the full-width layout', true, str_contains($body, 'class="messages-container"'));
[, $body] = $browser->get('/discover');
check('opening the conversation clears the unread indicator', false, str_contains($body, 'class="peer-unread-dot"'));
[$code, $body] = $browser->get('/api/messages/unread');
check('opening the conversation clears unread polling state', false, in_array('alice', json_decode($body, true)['unread'] ?? [], true));
$remoteBrowser->post('/messages/test', ['message' => '<script>alert(1)</script>']);
[, $body] = $browser->get('/messages/alice');
check('peer messages are escaped before rendering', true, str_contains($body, '&lt;script&gt;'));

$unreachableProbe = stream_socket_server('tcp://127.0.0.1:0');
$unreachablePort = (int)explode(':', stream_socket_get_name($unreachableProbe, false))[1];
fclose($unreachableProbe);
$unreachablePeers = json_encode(
    [['username' => 'alice', 'url' => "http://127.0.0.1:$unreachablePort", 'token' => $peerToken]],
    JSON_THROW_ON_ERROR
);
file_put_contents($tmp . '/.env', "USERNAME=test\nPASSWORD=$hash\nCRON_SECRET=$secret\nAPI_UPLOAD_SECRET=$apiSecret\nMAX_UPLOAD_MB=3072\nPEERS_JSON=$unreachablePeers\n");
[$code, $body] = $browser->get('/discover');
check('unreachable peer message action is disabled', true, str_contains($body, 'class="peer-message-button" type="button" disabled'));
check('unreachable peer message action has no conversation link', false, str_contains($body, 'href="/messages/alice"'));
[$code] = $browser->get('/messages/alice');
check('direct conversation URL redirects when peer is unreachable', 302, $code);
check('direct conversation URL redirects to discovery', true,
    str_contains(implode("\n", $browser->responseHeaders()), 'Location: /discover'));
[$code, $body] = $browser->get('/api/messages/alice');
check('message polling API rejects an unreachable peer', 502, $code);
check('message polling API does not expose cached conversation history', false, str_contains($body, 'Hello Alice'));
[$code] = $browser->post('/messages/alice', ['message' => 'Should not be sent']);
check('direct message POST redirects when peer is unreachable', 302, $code);
check('direct message POST redirects to discovery', true,
    str_contains(implode("\n", $browser->responseHeaders()), 'Location: /discover'));
$localHistory = json_decode(file_get_contents($tmp . '/data/messages/' . hash('sha256', 'alice') . '.json'), true);
check('message POST to unreachable peer is not stored', false,
    in_array('Should not be sent', array_column($localHistory, 'message'), true));

file_put_contents($tmp . '/.env', "USERNAME=test\nPASSWORD=$hash\nCRON_SECRET=$secret\nAPI_UPLOAD_SECRET=$apiSecret\nMAX_UPLOAD_MB=3072\nPEERS_JSON=$localPeers\n");
[, $body] = $browser->get('/discover');
check('reachable peer message action remains a link', true, str_contains($body, 'href="/messages/alice"'));
[$code] = $browser->get('/messages/alice');
check('reachable peer conversation remains accessible', 200, $code);

echo "dynamic peer management\n";
$dynamicToken = bin2hex(random_bytes(32));
[$code] = $browser->post('/peers/add', [
    'username' => 'charlie',
    'url' => 'https://charlie.example',
    'token' => $dynamicToken,
]);
check('a valid dynamic peer is added', 302, $code);
$dynamicPeers = json_decode(file_get_contents($tmp . '/data/peers.json'), true);
check('dynamic peer is persisted outside the environment file', 'charlie', $dynamicPeers[0]['username'] ?? null);
[, $body] = $browser->get('/discover');
check('dynamic peer appears in discovery', true, str_contains($body, 'charlie'));
check('peer token is not rendered in discovery', false, str_contains($body, $dynamicToken));

[$code] = $browser->post('/peers/add', [
    'username' => 'alice',
    'url' => 'https://another.example',
    'token' => bin2hex(random_bytes(32)),
]);
check('duplicate peer username is rejected', 302, $code);
check('duplicate username is not persisted', 1, count(json_decode(file_get_contents($tmp . '/data/peers.json'), true)));

[$code] = $browser->post('/peers/add', [
    'username' => 'insecure',
    'url' => 'http://192.168.1.50:8000',
    'token' => bin2hex(random_bytes(32)),
]);
check('HTTP peer with an IP address and port is accepted', 302, $code);
$dynamicPeers = json_decode(file_get_contents($tmp . '/data/peers.json'), true);
check('HTTP IP and port are persisted', 'http://192.168.1.50:8000', $dynamicPeers[1]['url'] ?? null);

[$code] = $browser->post('/peers/add', [
    'username' => 'hostname',
    'url' => 'http://alice.example:8000',
    'token' => bin2hex(random_bytes(32)),
]);
check('HTTP hostname is rejected', 302, $code);
check('HTTP hostname is not persisted', 2, count(json_decode(file_get_contents($tmp . '/data/peers.json'), true)));

[$code] = $browser->post('/peers/add', [
    'username' => 'noport',
    'url' => 'http://192.168.1.60',
    'token' => bin2hex(random_bytes(32)),
]);
check('HTTP IP without an explicit port is rejected', 302, $code);
check('HTTP IP without port is not persisted', 2, count(json_decode(file_get_contents($tmp . '/data/peers.json'), true)));

[$code] = $browser->post('/peers/add', [
    'username' => 'publicip',
    'url' => 'http://8.8.8.8:8000',
    'token' => bin2hex(random_bytes(32)),
]);
check('HTTP public IP is rejected', 302, $code);
check('HTTP public IP is not persisted', 2, count(json_decode(file_get_contents($tmp . '/data/peers.json'), true)));

[$code] = $browser->post('/peers/add', [
    'username' => 'private172',
    'url' => 'http://172.20.0.5:8000',
    'token' => bin2hex(random_bytes(32)),
]);
check('HTTP RFC1918 172.16/12 address is accepted', 302, $code);
[$code] = $browser->post('/peers/add', [
    'username' => 'private10',
    'url' => 'http://10.0.0.5:8000',
    'token' => bin2hex(random_bytes(32)),
]);
check('HTTP RFC1918 10/8 address is accepted', 302, $code);
[$code] = $browser->post('/peers/add', [
    'username' => 'privatev6',
    'url' => 'http://[fd00::5]:8000',
    'token' => bin2hex(random_bytes(32)),
]);
check('HTTP IPv6 unique-local address is accepted', 302, $code);
[$code] = $browser->post('/peers/add', [
    'username' => 'localhost',
    'url' => 'http://localhost:8000',
    'token' => bin2hex(random_bytes(32)),
]);
check('HTTP localhost with an explicit port is accepted', 302, $code);

[$code] = $browser->post('/peers/add', [
    'username' => 'tailscale-host',
    'url' => 'http://alice.tailnet.ts.net:8000',
    'token' => bin2hex(random_bytes(32)),
]);
check('HTTP Tailscale MagicDNS hostname is accepted', 302, $code);
$dynamicPeers = json_decode(file_get_contents($tmp . '/data/peers.json'), true);
check('HTTP Tailscale MagicDNS hostname is persisted', 'http://alice.tailnet.ts.net:8000', $dynamicPeers[6]['url'] ?? null);

[$code] = $browser->post('/peers/add', [
    'username' => 'tailscale-ip',
    'url' => 'http://100.100.10.20:8000',
    'token' => bin2hex(random_bytes(32)),
]);
check('HTTP Tailscale CGNAT address is accepted', 302, $code);

[$code] = $browser->post('/peers/add', [
    'username' => 'tailscale-ip-outside-range',
    'url' => 'http://100.128.10.20:8000',
    'token' => bin2hex(random_bytes(32)),
]);
check('HTTP address outside Tailscale CGNAT range is rejected', 302, $code);
$dynamicPeers = json_decode(file_get_contents($tmp . '/data/peers.json'), true);
check('HTTP address outside Tailscale CGNAT range is not persisted', 8, count($dynamicPeers));

[$code] = $browser->post('/peers/add', [
    'username' => 'tailscale-host-no-port',
    'url' => 'http://alice.tailnet.ts.net',
    'token' => bin2hex(random_bytes(32)),
]);
check('HTTP Tailscale MagicDNS hostname still requires an explicit port', 302, $code);

[$code] = $browser->post('/peers/add', [
    'username' => 'invalid-tailscale-host',
    'url' => 'http://bad..ts.net:8000',
    'token' => bin2hex(random_bytes(32)),
]);
check('malformed Tailscale MagicDNS hostname is rejected', 302, $code);

$browser->post('/peers/delete', ['username' => 'alice']);
check('static environment peer cannot be removed in the UI', 'charlie', json_decode(file_get_contents($tmp . '/data/peers.json'), true)[0]['username'] ?? null);
$browser->post('/peers/delete', ['username' => 'charlie']);
check('dynamic peer can be removed', 'insecure', json_decode(file_get_contents($tmp . '/data/peers.json'), true)[0]['username'] ?? null);
$browser->post('/peers/delete', ['username' => 'insecure']);
$browser->post('/peers/delete', ['username' => 'private172']);
$browser->post('/peers/delete', ['username' => 'private10']);
$browser->post('/peers/delete', ['username' => 'privatev6']);
$browser->post('/peers/delete', ['username' => 'localhost']);
$browser->post('/peers/delete', ['username' => 'tailscale-host']);
$browser->post('/peers/delete', ['username' => 'tailscale-ip']);
check('dynamic HTTP peers can be removed', [], json_decode(file_get_contents($tmp . '/data/peers.json'), true));
check('static peer remains configured after dynamic deletion', true, str_contains($browser->get('/discover')[1], 'alice'));

$browser->get('/logout');
$clearedCookie = implode('; ', array_filter($browser->responseHeaders(), fn($header) => stripos($header, 'Set-Cookie:') === 0));
check('HTTP logout clears the non-Secure session cookie', false, str_contains($clearedCookie, 'Secure'));
check('HTTP logout expires the session cookie', true, str_contains($clearedCookie, '1970') || str_contains($clearedCookie, 'expires=Thu, 01 Jan 1970'));

$browser->login('test', $password);
$browser->post('/account/password', [
    'current_password' => 'incorrect-current-password',
    'new_password' => 'new-test-password-long-enough',
    'confirm_password' => 'new-test-password-long-enough',
]);
check('password change rejects an incorrect current password', true,
    str_contains($browser->get('/discover?account=password')[1], 'Le mot de passe actuel est incorrect.'));
$browser->post('/account/password', [
    'current_password' => $password,
    'new_password' => 'short',
    'confirm_password' => 'short',
]);
check('password change enforces minimum length', true,
    str_contains($browser->get('/discover?account=password')[1], 'au moins 12 caractères'));
$newPassword = 'new-test-password-long-enough';
$browser->post('/account/password', [
    'current_password' => $password,
    'new_password' => $newPassword,
    'confirm_password' => $newPassword,
]);
check('password change reports success', true,
    str_contains($browser->get('/discover?account=password')[1], 'Mot de passe modifié avec succès.'));
$savedPassword = json_decode(file_get_contents($tmp . '/data/password.json'), true);
check('new password hash is persisted separately from .env', true,
    is_string($savedPassword['hash'] ?? null) && password_verify($newPassword, $savedPassword['hash']));
check('stored password hash has restrictive permissions', 0600, fileperms($tmp . '/data/password.json') & 0777);
$oldPasswordBrowser = new Browser($base);
$oldPasswordBrowser->get('/');
[, $oldLoginBody] = $oldPasswordBrowser->post('/login', ['username' => 'test', 'password' => $password]);
check('old password no longer authenticates', true,
    str_contains($oldLoginBody, 'Invalid credentials.'));
$newPasswordBrowser = new Browser($base);
$newPasswordBrowser->login('test', $newPassword);
check('new password authenticates', true,
    str_contains($newPasswordBrowser->get('/')[1], 'dashboard-toolbar'));

echo $failures === 0 ? "\nPASS\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
