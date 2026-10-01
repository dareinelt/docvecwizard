<?php

declare(strict_types=1);

use App\Services\ProcessingService;

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
