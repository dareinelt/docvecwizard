<?php

declare(strict_types=1);

/**
 * HTTP front controller. Nginx proxies /api/* and /healthz here; PHP-FPM
 * executes this script for every request. No framework, no Composer.
 */

use App\Core\Logger;
use App\Controllers\ApiController;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Security\Csrf;
use App\Security\CsrfException;

require __DIR__ . '/../config/bootstrap.php';

$request = Request::fromGlobals();
$router = new Router();
(new ApiController())->register($router);

// Serve the CSRF/session cookie on every response so the browser can obtain it.
Csrf::ensureSessionCookie();

try {
    $response = $router->dispatch($request);
} catch (CsrfException $e) {
    $response = Response::error($e->getMessage(), 403);
} catch (\InvalidArgumentException $e) {
    $response = Response::error($e->getMessage(), 400);
} catch (\Throwable $e) {
    Logger::channel('http')->error('request failed', [
        'method' => $request->method,
        'path' => $request->path,
        'error' => $e->getMessage(),
    ]);
    $response = Response::error('Internal server error', 500);
}

$response->send();
