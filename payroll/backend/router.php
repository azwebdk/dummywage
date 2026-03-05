<?php
/**
 * PHP Built-in Server Router
 * Routes API requests to the API handler and serves static files
 *
 * Usage: php -S localhost:8080 router.php
 */

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// API requests
if (strpos($path, '/api/') === 0 || $path === '/api') {
    // Rewrite to API handler
    $_SERVER['REQUEST_URI'] = $uri;
    require __DIR__ . '/api/index.php';
    return true;
}

// Serve the React frontend for root
if ($path === '/' || $path === '/index.html') {
    header('Content-Type: text/html');
    readfile(__DIR__ . '/../frontend/index.html');
    return true;
}

// Serve static files from frontend directory
$staticFile = __DIR__ . '/../frontend' . $path;
if (file_exists($staticFile) && !is_dir($staticFile)) {
    return false; // Let PHP built-in server handle it
}

// SPA fallback - serve index.html for all other routes
header('Content-Type: text/html');
readfile(__DIR__ . '/../frontend/index.html');
return true;
