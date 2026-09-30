<?php

declare(strict_types=1);

use App\Security\PathGuard;

$root = sys_get_temp_dir() . '/docvec_pathguard_' . bin2hex(random_bytes(4));
mkdir($root, 0777, true);
mkdir($root . '/sub', 0777, true);
file_put_contents($root . '/a.txt', 'a');
file_put_contents($root . '/sub/b.txt', 'b');

test('PathGuard::resolve keeps a path inside the root', function () use ($root): void {
    assert_eq(realpath($root . '/a.txt'), PathGuard::resolve($root, 'a.txt'));
});

test('PathGuard::resolve handles nested relative paths', function () use ($root): void {
    assert_eq(realpath($root . '/sub/b.txt'), PathGuard::resolve($root, 'sub/b.txt'));
});

test('PathGuard::resolve normalises backslashes', function () use ($root): void {
    assert_eq(realpath($root . '/sub/b.txt'), PathGuard::resolve($root, 'sub\\b.txt'));
});

test('PathGuard::resolve treats the root itself as "."', function () use ($root): void {
    assert_eq(realpath($root), PathGuard::resolve($root, '.'));
});

test('PathGuard::resolve rejects ".." traversal', function () use ($root): void {
    assert_throws(InvalidArgumentException::class, function () use ($root): void {
        PathGuard::resolve($root, '../etc/passwd');
    });
});

test('PathGuard::resolve rejects a traversal that resolves to a sibling', function () use ($root): void {
    assert_throws(InvalidArgumentException::class, function () use ($root): void {
        PathGuard::resolve($root . '/sub', '../a.txt');
    });
});

test('PathGuard::resolve rejects null bytes', function () use ($root): void {
    assert_throws(InvalidArgumentException::class, function () use ($root): void {
        PathGuard::resolve($root, "a\0b.txt");
    });
});

test('PathGuard::resolve rejects a missing root', function (): void {
    assert_throws(InvalidArgumentException::class, function (): void {
        PathGuard::resolve('/no/such/root/anywhere', 'a.txt');
    });
});

test('PathGuard::validateName accepts plain names', function (): void {
    assert_true(PathGuard::validateName('dokument 2024 (final).pdf'));
    assert_true(PathGuard::validateName('Änderungen-ö.txt'));
});

test('PathGuard::validateName rejects separators and dot-entries', function (): void {
    assert_false(PathGuard::validateName('..'));
    assert_false(PathGuard::validateName('.'));
    assert_false(PathGuard::validateName(''));
    assert_false(PathGuard::validateName('a/b'));
    assert_false(PathGuard::validateName('a\\b'));
});

test('PathGuard::normalise converts backslashes and strips slashes', function (): void {
    assert_eq('sub/dir/file.txt', PathGuard::normalise('\\sub\\dir\\file.txt\\'));
    assert_eq('file.txt', PathGuard::normalise('/file.txt/'));
});

register_shutdown_function(static function () use ($root): void {
    @unlink($root . '/sub/b.txt');
    @unlink($root . '/a.txt');
    @rmdir($root . '/sub');
    @rmdir($root);
});
