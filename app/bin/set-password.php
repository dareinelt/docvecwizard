<?php

declare(strict_types=1);

/**
 * Create a user or reset its password.
 *
 * Usage (inside the app container):
 *   docker compose exec app php bin/set-password.php <username>
 *
 * The password is read from STDIN (prompted without echo when interactive) or
 * from the DOCVEC_NEW_PASSWORD environment variable for automation. It is never
 * accepted as a command-line argument (would leak via `ps` / shell history).
 */

use App\Security\LoginThrottle;
use App\Services\Audit;
use App\Services\UserService;

require __DIR__ . '/../config/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$username = (string) ($argv[1] ?? '');
if (!UserService::isValidUsername($username)) {
    fwrite(STDERR, "Usage: php bin/set-password.php <username>\n(3-64 chars: A-Z a-z 0-9 . _ @ -)\n");
    exit(2);
}

function readSecret(string $prompt): string
{
    $interactive = function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($interactive) {
        fwrite(STDOUT, $prompt);
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($interactive) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }

    return rtrim((string) $line, "\r\n");
}

$password = (string) getenv('DOCVEC_NEW_PASSWORD');
if ($password === '') {
    $password = readSecret('New password: ');
    if (function_exists('posix_isatty') && posix_isatty(STDIN) && readSecret('Repeat password: ') !== $password) {
        fwrite(STDERR, "Passwords do not match.\n");
        exit(1);
    }
}

$violations = UserService::passwordPolicyViolations($password, $username);
if ($violations !== []) {
    fwrite(STDERR, implode("\n", $violations) . "\n");
    exit(1);
}

$users = new UserService();
$existing = $users->findByUsername($username);
if ($existing === null) {
    $id = $users->create($username, $password);
    Audit::record('auth.user_created', 'user', (string) $id, ['via' => 'cli']);
    fwrite(STDOUT, "User '{$username}' created.\n");
} else {
    $users->setPassword((int) $existing['id'], $password);
    (new LoginThrottle())->clear($username);
    Audit::record('auth.password_reset', 'user', (string) $existing['id'], ['via' => 'cli']);
    fwrite(STDOUT, "Password for '{$username}' updated.\n");
}
exit(0);
