<?php

declare(strict_types=1);

use App\Domain\JobStatus;
use App\Services\JobService;

test('JobStatus has no PAUSED case and knows terminal states', function (): void {
    assert_eq(null, JobStatus::tryFrom('PAUSED'));
    assert_eq(['CREATED', 'RUNNING', 'COMPLETED', 'FAILED', 'CANCELLED'], array_map(static fn (JobStatus $s): string => $s->value, JobStatus::cases()));
    assert_false(JobStatus::Created->isTerminal());
    assert_false(JobStatus::Running->isTerminal());
    assert_true(JobStatus::Completed->isTerminal());
    assert_true(JobStatus::Failed->isTerminal());
    assert_true(JobStatus::Cancelled->isTerminal());
});

test('only CANCELLED jobs are resumable', function (): void {
    foreach (JobStatus::cases() as $status) {
        assert_eq($status === JobStatus::Cancelled, $status->isResumable(), $status->value);
    }
});

test('finalStatus is FAILED only when nothing succeeded', function (): void {
    assert_eq('COMPLETED', JobService::finalStatus(0, 0));
    assert_eq('COMPLETED', JobService::finalStatus(0, 5));
    assert_eq('COMPLETED', JobService::finalStatus(3, 1));
    assert_eq('FAILED', JobService::finalStatus(1, 0));
    assert_eq('FAILED', JobService::finalStatus(7, 0));
});
