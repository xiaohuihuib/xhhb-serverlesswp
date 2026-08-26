<?php
// Router script for PHP's built-in test server.
// Blocks access to sensitive files under /wp-content/uploads/ so that
// uploaded .php, .sql, .db, .log, etc. cannot be executed or downloaded.

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$lowerUri = strtolower($uri);
$uploadsPrefix = '/wp-content/uploads/';

if (strpos($lowerUri, $uploadsPrefix) === 0) {
    if (substr($lowerUri, -1) === '/') {
        http_response_code(404);
        header('Cache-Control: no-store');
        echo 'Not Found';
        return true;
    }

    if (preg_match('/\.(php|sql|sqlite3?|db|log|env|ini)$/i', $uri)) {
        http_response_code(404);
        header('Cache-Control: no-store');
        echo 'Not Found';
        return true;
    }
}

return false;
