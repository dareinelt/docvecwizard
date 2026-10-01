<?php

declare(strict_types=1);

use App\Services\SearchService;

test('candidateWindows over-fetches and escalates once', function (): void {
    assert_eq([50, 1000], SearchService::candidateWindows(10));
    assert_eq([20, 1000], SearchService::candidateWindows(1));
    assert_eq([250, 1000], SearchService::candidateWindows(50));
    assert_eq([250, 1000], SearchService::candidateWindows(500));
});

test('filterCurrent keeps only hits of current versions, preserves order and caps at limit', function (): void {
    $hits = [
        ['id' => 'a', 'document_version_id' => 'v-old'],
        ['id' => 'b', 'document_version_id' => 'v-cur-1'],
        ['id' => 'c', 'document_version_id' => 'v-old'],
        ['id' => 'd', 'document_version_id' => 'v-cur-2'],
        ['id' => 'e', 'document_version_id' => 'v-cur-1'],
        ['id' => 'f', 'document_version_id' => ''],
        ['id' => 'g'],
    ];
    $queried = null;
    $lookup = function (array $ids) use (&$queried): array {
        $queried = $ids;
        return ['v-cur-1' => true, 'v-cur-2' => true];
    };

    $kept = SearchService::filterCurrent($hits, $lookup, 10);
    assert_eq(['b', 'd', 'e'], array_column($kept, 'id'));
    // The lookup is called once with the distinct version ids only.
    sort($queried);
    assert_eq(['v-cur-1', 'v-cur-2', 'v-old'], $queried);

    $kept = SearchService::filterCurrent($hits, $lookup, 2);
    assert_eq(['b', 'd'], array_column($kept, 'id'));
});

test('filterCurrent without any version ids never calls the lookup', function (): void {
    $called = false;
    $kept = SearchService::filterCurrent([['id' => 'x']], function () use (&$called): array { $called = true; return []; }, 5);
    assert_eq([], $kept);
    assert_false($called);
});
