<?php

declare(strict_types=1);

use App\Services\ExportService;

test('isSafeArchivePath accepts ordinary relative entries', function (): void {
    foreach (['manifest.json', 'documents/abc/original.pdf', 'documents/abc/chunks.json', 'a/b/c.d'] as $path) {
        assert_true(ExportService::isSafeArchivePath($path), $path);
    }
});

test('isSafeArchivePath rejects empty and absolute paths', function (): void {
    assert_false(ExportService::isSafeArchivePath(''));
    assert_false(ExportService::isSafeArchivePath('/etc/passwd'));
    assert_false(ExportService::isSafeArchivePath('/documents/x'));
});

test('isSafeArchivePath rejects parent-directory traversal anywhere in the path', function (): void {
    foreach (['../x', 'documents/../../etc/passwd', 'a/..', '..', 'a/..b/c'] as $path) {
        assert_false(ExportService::isSafeArchivePath($path), $path);
    }
});

test('isSafeArchivePath rejects NUL bytes', function (): void {
    assert_false(ExportService::isSafeArchivePath("documents/x\0.pdf"));
});
