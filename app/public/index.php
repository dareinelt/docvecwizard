<?php

declare(strict_types=1);

/**
 * HTTP front controller. Nginx proxies /api/* and /healthz here; PHP-FPM
 * executes this script for every request. No framework, no Composer.
 */

use App\Controllers\Routes;
use App\Core\Logger;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\UpstreamException;
use App\Security\AccessGuard;

require __DIR__ . '/../config/bootstrap.php';

$request = Request::fromGlobals();
$router = new Router();
// SECURITY FIX: central authentication + CSRF guard for every route
// (previously no authentication existed and CSRF checks were opt-in per action).
$router->setGuard(new AccessGuard());
Routes::register($router);

try {
    $response = $router->dispatch($request);
} catch (HttpException $e) {
    $response = Response::error($e->getMessage(), $e->status);
    if ($e->retryAfter > 0) {
        $response->withHeader('Retry-After', (string) $e->retryAfter);
    }
} catch (\InvalidArgumentException $e) {
    // Validation errors raised by services carry user-facing messages.
    $response = Response::error($e->getMessage(), 400);
} catch (UpstreamException $e) {
    // SECURITY FIX: upstream errors contain internal URLs / service output and
    // are no longer echoed to the client.
    Logger::channel('http')->error('upstream service failed', [
        'method' => $request->method,
        'path' => $request->path,
        'error' => $e->getMessage(),
    ]);
    $response = Response::error('Ein abhängiger Dienst (Milvus, Embedding oder Konverter) ist nicht erreichbar oder meldet einen Fehler.', 502);
} catch (\Throwable $e) {
    Logger::channel('http')->error('request failed', [
        'method' => $request->method,
        'path' => $request->path,
        'exception' => $e::class,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    $response = Response::error('Interner Serverfehler. Details stehen im Server-Log.', 500);
}

$response->send();
