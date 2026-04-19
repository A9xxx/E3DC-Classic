<?php
// api_file.php
require_once 'helpers.php';
$paths = getInstallPaths();

$allowedFiles = [
    'awattardebug.txt',
    'awattardebug.0.txt',
    'awattardebug.12.txt',
    'awattardebug.13.txt',
    'awattardebug.14.txt',
    'awattardebug.23.txt'
];

$file = $_GET['file'] ?? '';

if (!in_array($file, $allowedFiles)) {
    http_response_code(403);
    die("Forbidden");
}

$filePath = rtrim($paths['install_path'], '/') . '/' . $file;

if (file_exists($filePath)) {
    header('Content-Type: text/plain; charset=utf-8');
    readfile($filePath);
} else {
    http_response_code(404);
    die("Not Found");
}
