<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Session;

/**
 * Authentication endpoints (NEW - the application previously had no
 * authentication at all; every API endpoint was anonymously usable).
 */
final class AuthController extends Controller
{
    /** Public. Current login state plus the session's CSRF token. */
    public function me(Request $request): Response
    {
        Session::start($request);
        $user = Auth::user();
        $token = Csrf::token();
        Session::release();

        return Response::json([
            'authenticated' => $user !== null,
            'user' => $user === null ? null : ['username' => $user['username']],
            'csrf_token' => $token,
        ]);
    }

    /** Legacy endpoint kept for API compatibility. */
    public function csrf(Request $request): Response
    {
        Session::start($request);
        $token = Csrf::token();
        Session::release();

        return Response::json(['csrf_token' => $token]);
    }

    /** Public, CSRF-protected (login CSRF), rate limited. */
    public function login(Request $request): Response
    {
        $username = trim($request->bodyString('username', '', 64));
        $password = $request->bodyString('password', '', 1024);
        $user = Auth::attempt($username, $password, $request->remoteAddr);
        $token = Csrf::token();
        Session::release();

        return Response::json([
            'authenticated' => true,
            'user' => ['username' => $user['username']],
            'csrf_token' => $token,
        ]);
    }

    public function logout(Request $request): Response
    {
        Auth::logout();
        // Fresh anonymous session so the login form keeps working without reload.
        Session::start($request);
        $token = Csrf::token();
        Session::release();

        return Response::json(['authenticated' => false, 'csrf_token' => $token]);
    }

    public function changePassword(Request $request): Response
    {
        $current = $request->bodyString('current_password', '', 1024);
        $new = $request->bodyString('new_password', '', 1024);
        // The access guard released the session lock; re-open it for writing.
        Session::resume($request);
        Auth::changePassword($current, $new);
        $token = Csrf::token();
        Session::release();

        return Response::json(['changed' => true, 'csrf_token' => $token]);
    }
}
