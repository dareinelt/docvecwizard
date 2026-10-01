<?php

declare(strict_types=1);

use App\Services\ProcessingService;
use App\Services\UploadService;

test('discoveryDecision: new file without any prior content', function (): void {
    assert_eq(ProcessingService::DECISION_NEW, ProcessingService::discoveryDecision(null, 'h1', false));
});

test('discoveryDecision: new path but identical content elsewhere is skipped as duplicate', function (): void {
    assert_eq(ProcessingService::DECISION_SKIP_DUPLICATE, ProcessingService::discoveryDecision(null, 'h1', true));
});

test('discoveryDecision: unchanged completed document is skipped', function (): void {
    $current = ['file_hash' => 'h1', 'processing_status' => 'COMPLETED'];
    assert_eq(ProcessingService::DECISION_SKIP_UNCHANGED, ProcessingService::discoveryDecision($current, 'h1', false));
    // The global-dedupe flag is irrelevant once a version exists at this path.
    assert_eq(ProcessingService::DECISION_SKIP_UNCHANGED, ProcessingService::discoveryDecision($current, 'h1', true));
});

test('discoveryDecision: unchanged document still being processed is skipped', function (): void {
    foreach (['DISCOVERED', 'PROCESSING'] as $status) {
        $current = ['file_hash' => 'h1', 'processing_status' => $status];
        assert_eq(ProcessingService::DECISION_SKIP_UNCHANGED, ProcessingService::discoveryDecision($current, 'h1', false), $status);
    }
});

test('discoveryDecision: unchanged FAILED or PENDING document is requeued', function (): void {
    foreach (['FAILED', 'PENDING'] as $status) {
        $current = ['file_hash' => 'h1', 'processing_status' => $status];
        assert_eq(ProcessingService::DECISION_REQUEUE, ProcessingService::discoveryDecision($current, 'h1', false), $status);
    }
});

test('discoveryDecision: changed content creates a new version regardless of status', function (): void {
    foreach (['COMPLETED', 'FAILED', 'PENDING', 'PROCESSING'] as $status) {
        $current = ['file_hash' => 'h1', 'processing_status' => $status];
        assert_eq(ProcessingService::DECISION_NEW_VERSION, ProcessingService::discoveryDecision($current, 'h2', false), $status);
    }
});

test('oversizeMessage: files within the limit pass', function (): void {
    assert_eq(null, ProcessingService::oversizeMessage(100, 100, '100'));
    assert_eq(null, ProcessingService::oversizeMessage(0, 1, '1'));
});

test('oversizeMessage: limit <= 0 disables the check', function (): void {
    assert_eq(null, ProcessingService::oversizeMessage(PHP_INT_MAX, 0, '0'));
    assert_eq(null, ProcessingService::oversizeMessage(10, -1, '-1'));
});

test('oversizeMessage: oversized files yield a German message naming size and limit', function (): void {
    $limit = UploadService::parseSize('100M');
    $message = ProcessingService::oversizeMessage(150 * 1024 ** 2, $limit, '100M');
    assert_true($message !== null, 'expected message');
    assert_contains('150.0 MB', $message);
    assert_contains('MAX_DOCUMENT_SIZE=100M', $message);
    assert_contains('zu groß', $message);
});

test('formatBytes picks a readable unit', function (): void {
    assert_eq('512 B', ProcessingService::formatBytes(512));
    assert_eq('2 KB', ProcessingService::formatBytes(2048));
    assert_eq('1.5 MB', ProcessingService::formatBytes((int) (1.5 * 1024 ** 2)));
    assert_eq('2.0 GB', ProcessingService::formatBytes(2 * 1024 ** 3));
});