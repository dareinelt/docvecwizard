<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Uuid;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\UploadedFile;
use App\Services\TlsService;
use App\Services\UploadService;
use App\Services\UserService;

// --- Request / UploadedFile ---------------------------------------------------

test('UploadedFile::normalise flattens files[] multi-uploads', function (): void {
    $files = UploadedFile::normalise([
        'files' => [
            'name' => ['a.pdf', 'b.txt'],
            'tmp_name' => ['/tmp/phpA', '/tmp/phpB'],
            'size' => [10, 20],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_PARTIAL],
        ],
    ]);
    assert_count(2, $files);
    assert_eq('a.pdf', $files[0]->name);
    assert_eq('/tmp/phpB', $files[1]->tmpName);
    assert_eq(UPLOAD_ERR_PARTIAL, $files[1]->error);
});

test('UploadedFile::normalise handles single file fields', function (): void {
    $files = UploadedFile::normalise(['archive' => ['name' => 'x.tar.gz', 'tmp_name' => '/tmp/x', 'size' => 5, 'error' => 0]]);
    assert_count(1, $files);
    assert_eq('archive', $files[0]->field);
    assert_false($files[0]->isOk(), 'not an uploaded file -> must not be accepted');
});

test('Request flags malformed JSON bodies', function (): void {
    $r = new Request(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/jobs', 'CONTENT_TYPE' => 'application/json'], [], [], '{broken');
    assert_true($r->malformedJson);
    assert_eq([], $r->body);
});

test('Request::bodyString rejects arrays instead of casting to "Array"', function (): void {
    $r = new Request(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/', 'CONTENT_TYPE' => 'application/json'], [], [], '{"name":["x"]}');
    $e = assert_throws(HttpException::class, fn () => $r->bodyString('name'));
    assert_eq(400, $e->status);
});

test('Request::queryInt clamps and falls back on invalid input', function (): void {
    $r = new Request(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/x?limit=9999&offset=abc', 'QUERY_STRING' => 'limit=9999&offset=abc'], [], [], '');
    assert_eq(500, $r->queryInt('limit', 100, 1, 500));
    assert_eq(0, $r->queryInt('offset', 0, 0));
});

test('Request::secure follows the HTTPS server variable', function (): void {
    assert_true((new Request(['HTTPS' => 'on'], [], [], ''))->secure);
    assert_false((new Request(['HTTPS' => 'off'], [], [], ''))->secure);
    assert_false((new Request([], [], [], ''))->secure);
});

// --- Router -----------------------------------------------------------------

function make_router(?Closure $guard = null): Router
{
    $router = new Router();
    if ($guard !== null) {
        $router->setGuard($guard);
    }
    $router->get('/public', fn () => Response::json(['ok' => true]), public: true);
    $router->get('/private/{id}', fn (Request $r, array $p) => Response::json(['id' => $p['id']]));
    $router->post('/private/{id}', fn () => Response::json(['posted' => true]));

    return $router;
}

test('Router returns 404 for unknown paths and 405 with Allow header', function (): void {
    $router = make_router();
    assert_eq(404, $router->dispatch(new Request(['REQUEST_URI' => '/nope'], [], [], ''))->status);
    $response = $router->dispatch(new Request(['REQUEST_METHOD' => 'DELETE', 'REQUEST_URI' => '/private/1'], [], [], ''));
    assert_eq(405, $response->status);
    assert_contains('GET', $response->headers['Allow']);
    assert_contains('POST', $response->headers['Allow']);
});

test('Router passes the public flag to the guard and lets it reject requests', function (): void {
    $seen = [];
    $router = make_router(function (Request $r, bool $public) use (&$seen): void {
        $seen[] = $public;
        if (!$public) {
            throw HttpException::unauthorized();
        }
    });
    assert_eq(200, $router->dispatch(new Request(['REQUEST_URI' => '/public'], [], [], ''))->status);
    $e = assert_throws(HttpException::class, fn () => $router->dispatch(new Request(['REQUEST_URI' => '/private/1'], [], [], '')));
    assert_eq(401, $e->status);
    assert_eq([true, false], $seen);
});

test('Router rejects malformed JSON on unsafe methods', function (): void {
    $router = make_router(fn () => null);
    $request = new Request(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/private/1', 'CONTENT_TYPE' => 'application/json'], [], [], '{');
    $e = assert_throws(HttpException::class, fn () => $router->dispatch($request));
    assert_eq(400, $e->status);
});

test('Router treats HEAD like GET', function (): void {
    $router = make_router();
    assert_eq(200, $router->dispatch(new Request(['REQUEST_METHOD' => 'HEAD', 'REQUEST_URI' => '/public'], [], [], ''))->status);
});

// --- Response ---------------------------------------------------------------

test('Response::download encodes the filename safely (no header injection)', function (): void {
    $response = Response::download('x', "evil\"\r\nX-Injected: 1 ä.pdf", 'application/pdf');
    $cd = $response->headers['Content-Disposition'];
    assert_not_contains("\r", $cd);
    assert_not_contains("\n", $cd);
    assert_contains("filename*=UTF-8''", $cd);
    assert_eq('nosniff', $response->headers['X-Content-Type-Options']);
    assert_contains('sandbox', $response->headers['Content-Security-Policy']);
});

test('Response::json disables caching', function (): void {
    assert_eq('no-store', Response::json([])->headers['Cache-Control']);
});

// --- Config / Uuid ----------------------------------------------------------

test('Config::isStrongSecret rejects empty, short and placeholder secrets', function (): void {
    assert_false(Config::isStrongSecret(''));
    assert_false(Config::isStrongSecret('short'));
    assert_false(Config::isStrongSecret('change-me-session-secret-change-me-session'));
    assert_true(Config::isStrongSecret(bin2hex(random_bytes(32))));
});

test('Config::secret throws for weak secrets', function (): void {
    Config::set('TEST_SECRET', 'change-me');
    assert_throws(RuntimeException::class, fn () => Config::secret('TEST_SECRET'));
    Config::set('TEST_SECRET', str_repeat('a1B2', 10));
    assert_eq(str_repeat('a1B2', 10), Config::secret('TEST_SECRET'));
});

test('Uuid::isValid accepts canonical UUIDs only', function (): void {
    assert_true(Uuid::isValid(Uuid::v4()));
    assert_false(Uuid::isValid(Uuid::v4() . "\n"), 'trailing newline must be rejected');
    assert_false(Uuid::isValid('x" || id != "'));
    assert_false(Uuid::isValid('../../etc/passwd'));
    assert_false(Uuid::isValid(''));
});

// --- Users / passwords ------------------------------------------------------

test('UserService validates user names', function (): void {
    assert_true(UserService::isValidUsername('admin'));
    assert_true(UserService::isValidUsername('jane.doe@example.org'));
    assert_false(UserService::isValidUsername('ab'));
    assert_false(UserService::isValidUsername("admin\n"));
    assert_false(UserService::isValidUsername('<script>'));
});

test('UserService password policy', function (): void {
    assert_true(UserService::passwordPolicyViolations('short', 'admin') !== []);
    assert_true(UserService::passwordPolicyViolations('administrator', 'administrator') !== []);
    assert_true(UserService::passwordPolicyViolations('change-me-please-now', 'admin') !== []);
    assert_eq([], UserService::passwordPolicyViolations('correct horse battery staple', 'admin'));
});

test('UserService::hash produces verifiable modern hashes', function (): void {
    $hash = UserService::hash('correct horse battery staple');
    assert_true(password_verify('correct horse battery staple', $hash));
    assert_false(password_verify('wrong', $hash));
    assert_true(str_starts_with($hash, '$argon2id$') || str_starts_with($hash, '$2y$'));
});

// --- TLS input validation -----------------------------------------------------

test('TlsService::validateSubject accepts host names and IPs', function (): void {
    [$cn, $san] = TlsService::validateSubject('docs.example.org', ['DNS:docs.example.org', 'IP:10.0.0.5', 'IP:::1', ' DNS:*.example.org ']);
    assert_eq('docs.example.org', $cn);
    assert_eq(['DNS:docs.example.org', 'IP:10.0.0.5', 'IP:::1', 'DNS:*.example.org'], $san);
});

test('TlsService::validateSubject blocks OpenSSL config injection', function (): void {
    assert_throws(InvalidArgumentException::class, fn () => TlsService::validateSubject('ok.example', ["DNS:a.example\n.include /etc/passwd"]));
    assert_throws(InvalidArgumentException::class, fn () => TlsService::validateSubject('ok.example', ['DNS:a,DNS:b']));
    assert_throws(InvalidArgumentException::class, fn () => TlsService::validateSubject('ok.example', ['email:x@y']));
    assert_throws(InvalidArgumentException::class, fn () => TlsService::validateSubject('ok.example', ['IP:999.1.1.1']));
    assert_throws(InvalidArgumentException::class, fn () => TlsService::validateSubject("bad\nname", []));
    assert_throws(InvalidArgumentException::class, fn () => TlsService::validateSubject('', []));
});

test('PathGuard::validateName rejects a trailing newline', function (): void {
    assert_false(\App\Security\PathGuard::validateName("report.pdf\n"));
    assert_true(\App\Security\PathGuard::validateName('report.pdf'));
});

// --- Upload limits ------------------------------------------------------------

test('UploadService::parseSize understands php.ini notation', function (): void {
    assert_eq(100 * 1024 * 1024, UploadService::parseSize('100M'));
    assert_eq(2 * 1024 ** 3, UploadService::parseSize('2G'));
    assert_eq(512 * 1024, UploadService::parseSize('512k'));
    assert_eq(1234, UploadService::parseSize('1234'));
    assert_eq(0, UploadService::parseSize('lots'));
});
