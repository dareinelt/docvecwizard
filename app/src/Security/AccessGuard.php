<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Router;

/**
 * Central access control invoked by the router before every handler:
 *   - private routes require an authenticated session
 *   - every unsafe method (POST/PUT/DELETE) requires a valid CSRF token,
 *     including public ones such as the login form (login CSRF)
 */
final class AccessGuard
{
    public function __invoke(Request $request, bool $isPublic): void
    {
        $unsafe = !Router::isSafeMethod($request->method);

        if ($isPublic) {
            if ($unsafe) {
                Session::start($request);
                $this->assertCsrf($request);
            }

            return;
        }

        Session::start($request);
        if (!Auth::check()) {
            Session::release();
            throw HttpException::unauthorized();
        }
        if ($unsafe) {
            $this->assertCsrf($request);
        }
        // Session data is not modified by regular API handlers; unlock it early.
        Session::release();
    }

    private function assertCsrf(Request $request): void
    {
        if (!Csrf::verify($request->header(Csrf::HEADER))) {
            Session::release();
            throw HttpException::forbidden('Ungültiges oder fehlendes CSRF-Token. Bitte die Seite neu laden.');
        }
    }
}
