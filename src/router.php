<?php
// Router for PHP built-in dev server (composer serve)
// Returns false to let the server serve static files directly,
// otherwise routes everything through index.php.
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!is_string($requestPath)) {
    http_response_code(400);
    exit('Invalid request path.');
}

$uploadsReal = realpath(dirname(__DIR__) . '/uploads');
if ($uploadsReal !== false) {
    foreach ([$requestPath, rawurldecode($requestPath)] as $path) {
        $requestedReal = realpath(__DIR__ . $path);
        if ($requestedReal === false) continue;

        $uploadsPrefix = rtrim($uploadsReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (DIRECTORY_SEPARATOR === '\\') {
            $requestedReal = strtolower($requestedReal);
            $uploadsReal = strtolower($uploadsReal);
            $uploadsPrefix = strtolower($uploadsPrefix);
        }
        if ($requestedReal === $uploadsReal || str_starts_with($requestedReal, $uploadsPrefix)) {
            http_response_code(403);
            exit('Direct access to uploaded files is forbidden.');
        }
    }
}

$file = __DIR__ . $requestPath;
if (is_file($file)) {
    return false;
}
require __DIR__ . '/index.php';
