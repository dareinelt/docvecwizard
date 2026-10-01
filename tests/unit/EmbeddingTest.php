<?php

declare(strict_types=1);

use App\Services\EmbeddingClient;
use App\Services\ModelMismatchException;

test('EmbeddingClient::assertDimension accepts vectors of the expected dimension', function (): void {
    EmbeddingClient::assertDimension([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]], 3, 'm');
    EmbeddingClient::assertDimension([], 1024, 'm');
});

test('EmbeddingClient::assertDimension rejects a vector of another dimension', function (): void {
    $e = assert_throws(RuntimeException::class, fn () => EmbeddingClient::assertDimension([[0.1, 0.2, 0.3], [0.4, 0.5]], 3, 'Qwen3-Embedding-0.6B'));
    assert_contains('Vektordimension 2', $e->getMessage());
    assert_contains('erwarteten Dimension 3', $e->getMessage());
    assert_contains('Qwen3-Embedding-0.6B', $e->getMessage());
    assert_contains('Vektor 1', $e->getMessage());
});

test('EmbeddingClient::assertDimension rejects non-array entries', function (): void {
    assert_throws(RuntimeException::class, fn () => EmbeddingClient::assertDimension(['oops'], 3, 'm'));
    assert_throws(RuntimeException::class, fn () => EmbeddingClient::assertDimension([null], 3, 'm'));
});

test('ModelMismatchException carries a German user-facing message', function (): void {
    $e = new ModelMismatchException('Qwen3-Embedding-4B', 'Qwen3-Embedding-0.6B');
    assert_eq('Qwen3-Embedding-4B', $e->expected);
    assert_eq('Qwen3-Embedding-0.6B', $e->loaded);
    assert_contains('"Qwen3-Embedding-4B"', $e->getMessage());
    assert_contains('geladen: Qwen3-Embedding-0.6B', $e->getMessage());
    assert_contains('geladen: keines', (new ModelMismatchException('x', ''))->getMessage());
});
