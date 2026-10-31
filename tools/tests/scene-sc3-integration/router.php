<?php

declare(strict_types=1);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && ! str_contains($path, '..') && is_file($_SERVER['DOCUMENT_ROOT'].$path)) {
    return false;
}
$app = require __DIR__.'/bootstrap.php';
$app->handleRequest(Illuminate\Http\Request::capture());
