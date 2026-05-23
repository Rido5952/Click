<?php
// router.php - Developer Local Router for PHP Built-in Server

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;

// If it's a physical file, let PHP's built-in server serve it directly
if (file_exists($file) && !is_dir($file)) {
    // Check if it's a standard PHP file
    if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
        include $file;
        return true;
    }
    return false;
}

// Otherwise, parse the query string and route to index.php
$path = ltrim($uri, '/');

if (!empty($path)) {
    $_GET['c'] = $path;
}

include __DIR__ . '/index.php';
