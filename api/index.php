<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
}

/*
|--------------------------------------------------------------------------
| Vercel Serverless Storage Initialization
|--------------------------------------------------------------------------
|
| The root filesystem in AWS Lambda / Vercel Serverless environment is
| read-only. We dynamically allocate and initialize the writable /tmp
| directory for Laravel framework caches, compiled views, sessions, and logs.
|
*/
$baseTemp = getenv('APP_STORAGE') ?: (DIRECTORY_SEPARATOR === '/' ? '/tmp/storage' : (rtrim(sys_get_temp_dir(), '/\\').'/taxiscanner_storage'));
$storagePath = rtrim($baseTemp, '/\\');

$storageDirs = [
    $storagePath.'/framework/views',
    $storagePath.'/framework/cache/data',
    $storagePath.'/framework/sessions',
    $storagePath.'/logs',
];

foreach ($storageDirs as $dir) {
    if (! is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// Ensure compiled views are written to writable storage
putenv('VIEW_COMPILED_PATH='.$storagePath.'/framework/views');
$_ENV['VIEW_COMPILED_PATH'] = $storagePath.'/framework/views';
$_SERVER['VIEW_COMPILED_PATH'] = $storagePath.'/framework/views';

// Prevent cache & sessions from attempting writes to read-only SQLite in serverless
if (! getenv('CACHE_STORE')) {
    putenv('CACHE_STORE=file');
    $_ENV['CACHE_STORE'] = 'file';
    $_SERVER['CACHE_STORE'] = 'file';
}
if (! getenv('SESSION_DRIVER')) {
    putenv('SESSION_DRIVER=array');
    $_ENV['SESSION_DRIVER'] = 'array';
    $_SERVER['SESSION_DRIVER'] = 'array';
}

/*
|--------------------------------------------------------------------------
| Header & Authorization Normalization
|--------------------------------------------------------------------------
|
| Ensure Authorization headers are correctly populated for Laravel request
| capture when behind serverless gateway / FastCGI proxies.
|
*/
if (! isset($_SERVER['HTTP_AUTHORIZATION'])) {
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = $headers['Authorization'];
        } elseif (isset($headers['authorization'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = $headers['authorization'];
        }
    }
}

/*
|--------------------------------------------------------------------------
| Maintenance Mode Check
|--------------------------------------------------------------------------
*/
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

/*
|--------------------------------------------------------------------------
| Register Composer Autoloader
|--------------------------------------------------------------------------
*/
require __DIR__.'/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Bootstrap Laravel Application
|--------------------------------------------------------------------------
*/
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

// Direct storage path to writable serverless /tmp
$app->useStoragePath($storagePath);

/*
|--------------------------------------------------------------------------
| Handle Request
|--------------------------------------------------------------------------
|
| Forward incoming request (GET, POST, PUT, PATCH, DELETE, OPTIONS)
| to Laravel and send the response.
|
*/
$app->handleRequest(Request::capture());
