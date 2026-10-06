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

/**
 * Set an env value only when it is not already provided by Vercel / the runtime.
 */
$setDefaultEnv = static function (string $key, string $value): void {
    $current = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($current !== false && $current !== null && $current !== '') {
        return;
    }

    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
};

try {
    /*
    |--------------------------------------------------------------------------
    | Writable paths for Vercel (read-only root FS except /tmp)
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
        '/tmp/views',
        '/tmp/cache',
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
    | bootstrap/cache is not writable on Vercel. Point every Laravel cache
    | file and compiled-view path at /tmp before the app boots, otherwise
    | bootstrap fails and exception rendering then blows up with
    | "Target class [view] does not exist".
    */
    $setDefaultEnv('APP_CONFIG_CACHE', '/tmp/config.php');
    $setDefaultEnv('APP_EVENTS_CACHE', '/tmp/events.php');
    $setDefaultEnv('APP_PACKAGES_CACHE', '/tmp/packages.php');
    $setDefaultEnv('APP_ROUTES_CACHE', '/tmp/routes.php');
    $setDefaultEnv('APP_SERVICES_CACHE', '/tmp/services.php');
    $setDefaultEnv('VIEW_COMPILED_PATH', '/tmp/views');
    $setDefaultEnv('LOG_CHANNEL', 'stderr');
    $setDefaultEnv('CACHE_STORE', 'file');
    $setDefaultEnv('SESSION_DRIVER', 'array');
    $setDefaultEnv('QUEUE_CONNECTION', 'sync');

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
    | Fix Vercel /api path rewriting
    |--------------------------------------------------------------------------
    | Vercel runs this file as SCRIPT_NAME=/api/index.php. Symfony then treats
    | "/api" as the base URL and strips it, so /api/v1/compare becomes
    | v1/compare — Laravel 404s, CORS paths (api/*) no longer match, and the
    | browser reports a CORS error. Normalize to a normal front-controller.
    */
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__).'/public/index.php';

    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $uriParts = parse_url($requestUri);
    $requestPath = $uriParts['path'] ?? '/';

    // Some vercel-php versions also pass a path already stripped of /api.
    if (
        $requestPath !== '/'
        && $requestPath !== '/up'
        && ! str_starts_with($requestPath, '/api')
    ) {
        $restored = '/api'.$requestPath;
        if (! empty($uriParts['query'])) {
            $restored .= '?'.$uriParts['query'];
        }
        $_SERVER['REQUEST_URI'] = $restored;
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

    $current = $e;

    $message = "\n===== ORIGINAL EXCEPTION CHAIN =====\n";

    while ($current) {
        $message .=
            get_class($current)."\n".
            $current->getMessage()."\n".
            "File: ".$current->getFile()."\n".
            "Line: ".$current->getLine()."\n\n";

        $current = $current->getPrevious();
    }

    $message .= "===== TRACE =====\n";
    $message .= $e->getTraceAsString();
    $message .= "\n==============================\n";

    error_log($message);

    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');

    echo $message;
}
