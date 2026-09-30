<?php

declare(strict_types=1);

/**
 * Minimal, dependency-free PHP unit-test runner.
 *
 * Usage: php tests/unit/run.php
 *
 * Each `*Test.php` file in this directory calls the global `test()` helper to
 * register a named case. The runner executes all cases, prints a concise
 * summary, and exits non-zero on the first failure.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// --- Autoloader for the App\ namespace (points at app/src) -----------------
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $root = dirname(__DIR__, 2) . '/app/src/';
    $relative = substr($class, strlen($prefix));
    $path = $root . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// --- Test registration + assertions ---------------------------------------
final class TestFailure extends RuntimeException
{
}

final class TestRegistry
{
    /** @var list<array{name:string,fn:Closure}> */
    public static array $tests = [];

    public static int $passed = 0;

    public static int $failed = 0;
}

function test(string $name, Closure $fn): void
{
    TestRegistry::$tests[] = ['name' => $name, 'fn' => $fn];
}

function fail(string $message): never
{
    throw new TestFailure($message);
}

function assert_true(mixed $actual, string $message = ''): void
{
    if ($actual !== true) {
        fail($message !== '' ? $message : 'expected true, got ' . var_export($actual, true));
    }
}

function assert_false(mixed $actual, string $message = ''): void
{
    if ($actual !== false) {
        fail($message !== '' ? $message : 'expected false, got ' . var_export($actual, true));
    }
}

function assert_eq(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        fail($message !== '' ? $message
            : 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected != $actual) {
        fail($message !== '' ? $message
            : 'expected (loose) ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        fail($message !== '' ? $message : 'expected to find "' . $needle . '" in "' . $haystack . '"');
    }
}

function assert_not_contains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        fail($message !== '' ? $message : 'expected not to find "' . $needle . '" in "' . $haystack . '"');
    }
}

function assert_count(int $expected, array|Countable $actual, string $message = ''): void
{
    $count = is_array($actual) ? count($actual) : count($actual);
    if ($count !== $expected) {
        fail($message !== '' ? $message : 'expected count ' . $expected . ', got ' . $count);
    }
}

/**
 * Assert that $fn throws an exception of type $class (or subclass).
 *
 * @template T
 * @param class-string<T> $class
 * @return T
 */
function assert_throws(string $class, Closure $fn, string $message = ''): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        fail($message !== '' ? $message
            : 'expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
    }
    fail($message !== '' ? $message : 'expected ' . $class . ', but nothing was thrown');
}

// --- Run --------------------------------------------------------------------
$testFiles = glob(__DIR__ . '/*Test.php');
sort($testFiles);
foreach ($testFiles as $file) {
    require $file;
}

foreach (TestRegistry::$tests as $case) {
    try {
        $case['fn']();
        TestRegistry::$passed++;
        fwrite(STDOUT, "ok   - {$case['name']}\n");
    } catch (Throwable $e) {
        TestRegistry::$failed++;
        fwrite(STDOUT, "FAIL - {$case['name']}\n");
        fwrite(STDOUT, "       " . get_class($e) . ': ' . $e->getMessage() . "\n");
        $file = $e->getFile();
        $line = $e->getLine();
        fwrite(STDOUT, "       at {$file}:{$line}\n");
    }
}

$total = TestRegistry::$passed + TestRegistry::$failed;
fwrite(STDOUT, "\n" . TestRegistry::$passed . "/{$total} tests passed\n");

exit(TestRegistry::$failed > 0 ? 1 : 0);
