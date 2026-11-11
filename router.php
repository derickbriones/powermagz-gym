<?php
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');

/* Mirror the Apache deny rules so the dev server never serves private files. */
// Allow an optional subfolder prefix so the guard also works when the app
// is served from /some-folder/ instead of the web root.
$privateDir = '#(?:^|/)(config|src|storage|tests|database|vendor)(?:/|$)#';
$privateFile = '#(?:^|/)\.env|\.(sql|log|md|lock)$|(?:^|/)(composer\.json|docker-compose\.yml)$#i';

if (preg_match($privateDir, $uri) || preg_match($privateFile, $uri)) {
    http_response_code(404);
    exit('Not found.');
}

if ($uri !== '/' && file_exists(__DIR__ . $uri)) return false;
require_once __DIR__ . '/index.php';
