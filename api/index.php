<?php
 
declare(strict_types=1);
 
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Throwable;
 
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');
 
if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
}
 
try {
    /*
    |--------------------------------------------------------------------------
    | Writable storage for Vercel
    |--------------------------------------------------------------------------
    */
    $storagePath = '/tmp/storage';
 
    $storageDirs = [
        $storagePath,
        $storagePath.'/framework',
        $storagePath.'/framework/cache',
        $storagePath.'/framework/cache/data',
        $storagePath.'/framework/sessions',
        $storagePath.'/framework/views',
        $storagePath.'/logs',
    ];
 
    foreach ($storageDirs as $dir) {
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException("Unable to create directory: {$dir}");
        }
    }
 
    /*
    |--------------------------------------------------------------------------
    | Serverless-safe Laravel settings
    |--------------------------------------------------------------------------
    */
    putenv('VIEW_COMPILED_PATH='.$storagePath.'/framework/views');
    $_ENV['VIEW_COMPILED_PATH'] = $storagePath.'/framework/views';
    $_SERVER['VIEW_COMPILED_PATH'] = $storagePath.'/framework/views';
 
    /*
    |--------------------------------------------------------------------------
    | Authorization header
    |--------------------------------------------------------------------------
    */
    if (! isset($_SERVER['HTTP_AUTHORIZATION'])) {
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $_SERVER['HTTP_AUTHORIZATION'] =
                $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
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
    | Composer
    |--------------------------------------------------------------------------
    */
    require __DIR__.'/../vendor/autoload.php';
 
    /*
    |--------------------------------------------------------------------------
    | Laravel
    |--------------------------------------------------------------------------
    */
 
    /** @var Application $app */
    $app = require __DIR__.'/../bootstrap/app.php';
 
    // IMPORTANT: set this before Laravel handles/bootstraps the request.
    $app->useStoragePath($storagePath);
 
    $app->handleRequest(Request::capture());
 
} catch (Throwable $e) {
 
    $message =
        "\n===== LARAVEL BOOT ERROR =====\n".
        get_class($e)."\n".
        $e->getMessage()."\n".
        "File: ".$e->getFile()."\n".
        "Line: ".$e->getLine()."\n".
        $e->getTraceAsString().
        "\n==============================\n";
 
    error_log($message);
 
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
 
    echo "Laravel failed to boot.\n\n";
    echo get_class($e)."\n";
    echo $e->getMessage()."\n";
    echo $e->getFile().':'.$e->getLine();
}