<?php

declare(strict_types=1);

/**
 * Integration tests for the Document Embedding Manager.
 *
 * These exercises the real service boundaries over the Docker network:
 *   PHP <-> MySQL, PHP <-> Milvus, Worker <-> Embedding, Worker <-> Converter.
 *
 * Usage (from the project root, with the stack running):
 *   tests/integration/run.sh
 * or directly inside the app container:
 *   php /app/tests/integration/run.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../../config/bootstrap.php';

use App\Core\Db;
use App\Services\ConverterClient;
use App\Services\EmbeddingClient;
use App\Services\MilvusClient;
use App\Services\ModelService;

// --- Minimal assertion helpers (dependency-free, mirrors tests/unit) --------
final class IntegrationFailure extends RuntimeException
{
}

/** @var list<array{name:string,fn:Closure}> */
$GLOBALS['__tests'] = [];
$GLOBALS['__passed'] = 0;
$GLOBALS['__failed'] = 0;

function test(string $name, Closure $fn): void
{
    $GLOBALS['__tests'][] = ['name' => $name, 'fn' => $fn];
}

function fail(string $message): never
{
    throw new IntegrationFailure($message);
}

function assert_true(mixed $actual, string $message = ''): void
{
    if ($actual !== true) {
        fail($message !== '' ? $message : 'expected true, got ' . var_export($actual, true));
    }
}

function assert_eq(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        fail($message !== '' ? $message : 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        fail($message !== '' ? $message : 'expected to find "' . $needle . '" in "' . $haystack . '"');
    }
}

// ---------------------------------------------------------------------------
// 1. PHP <-> MySQL
// ---------------------------------------------------------------------------
test('MySQL: connection is alive', function (): void {
    assert_eq(1, Db::fetchValue('SELECT 1'), 'SELECT 1 must return 1');
});

test('MySQL: core tables exist', function (): void {
    $expected = [
        'schema_migrations', 'settings', 'embedding_models', 'jobs',
        'documents', 'document_chunks', 'processing_errors',
        'system_metrics', 'audit_log', 'tls_certificates', 'exports',
    ];
    $rows = Db::fetchAll(
        "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()"
    );
    $tables = array_column($rows, 'table_name');
    foreach ($expected as $table) {
        assert_true(in_array($table, $tables, true), "missing table: {$table}");
    }
});

test('MySQL: settings round-trip', function (): void {
    Db::execute("INSERT INTO settings (`key`, `value`) VALUES ('itest_key', 'itest_value') ON DUPLICATE KEY UPDATE `value` = 'itest_value'");
    assert_eq('itest_value', Db::fetchValue('SELECT `value` FROM settings WHERE `key` = ?', ['itest_key']));
    Db::execute('DELETE FROM settings WHERE `key` = ?', ['itest_key']);
    assert_eq(null, Db::fetchValue('SELECT `value` FROM settings WHERE `key` = ?', ['itest_key']));
});

// ---------------------------------------------------------------------------
// 2. PHP <-> Milvus
// ---------------------------------------------------------------------------
test('Milvus: health and existing collection', function (): void {
    $milvus = new MilvusClient();
    assert_true($milvus->health(), 'Milvus /healthz must report healthy');
    $collections = $milvus->listCollections();
    assert_true(is_array($collections) && $collections !== [], 'at least one collection must exist');
});

test('Milvus: create/insert/flush/search/drop round-trip', function (): void {
    $model = (new ModelService())->active() ?? (new ModelService())->all()[0] ?? null;
    if ($model === null) {
        fail('no embedding model configured');
    }
    $dim = (int) $model['dimension'];
    $collection = 'docvec_itest_' . substr(md5((string) mt_rand()), 0, 8);

    $milvus = new MilvusClient();
    try {
        $milvus->createCollection($collection, $dim, (string) $model['distance_metric']);
        assert_true($milvus->hasCollection($collection), 'test collection must be listed after create');

        $vector = array_fill(0, $dim, 0.0);
        $vector[0] = 1.0;
        // The stable-ID schema (autoID=false) requires the full field set; a
        // partial row fails with Milvus "Int64" parse errors on missing fields.
        $milvus->insert($collection, [[
            'id' => 'itest-vec-' . substr(md5((string) mt_rand()), 0, 8),
            'document_id' => 'itest-doc',
            'document_version_id' => 'itest-ver',
            'chunk_id' => 'itest-chunk',
            'job_id' => 'itest-job',
            'source_path' => '/srv/data/input/fixtures/sample.txt',
            'filename' => 'sample.txt',
            'document_hash' => str_repeat('a', 64),
            'embedding_model' => (string) $model['name'],
            'embedding_dimension' => $dim,
            'chunk_index' => 0,
            'page_start' => 0,
            'page_end' => 0,
            'vector' => $vector,
        ]]);
        $milvus->flush($collection);

        // After flush the row count can lag briefly; poll with a bounded retry.
        $rowCount = -1;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $stats = $milvus->collectionStats($collection);
            $rowCount = (int) ($stats['data']['rowCount'] ?? -1);
            if ($rowCount === 1) {
                break;
            }
            usleep(500_000);
        }
        assert_eq(1, $rowCount, 'rowCount must be 1 after insert+flush');

        $result = $milvus->search($collection, [$vector], 3, (string) $model['distance_metric']);
        $hits = $result['data'] ?? [];
        assert_true(count($hits) >= 1, 'search must return at least one hit');
        assert_eq('itest-doc', $hits[0]['document_id'] ?? null, 'search must return our document_id');
    } finally {
        $milvus->dropCollection($collection);
    }
    assert_true(!$milvus->hasCollection($collection), 'test collection must be dropped');
});

// ---------------------------------------------------------------------------
// 3. Worker <-> Embedding
// ---------------------------------------------------------------------------
test('Embedding: health and active model', function (): void {
    $embedding = new EmbeddingClient();
    $health = $embedding->health();
    assert_eq('ok', $health['status'] ?? null, 'embedding health status must be ok');
    assert_true((bool) ($health['model_loaded'] ?? false), 'embedding model must be loaded');
    assert_contains('Qwen3', (string) ($health['model'] ?? ''), 'active model must be a Qwen3 model');
});

test('Embedding: batch embed dimension matches DB metadata', function (): void {
    $model = (new ModelService())->active() ?? (new ModelService())->all()[0] ?? null;
    if ($model === null) {
        fail('no embedding model configured');
    }
    $vectors = (new EmbeddingClient())->embedBatch(['Hallo Welt', 'Document Embedding Manager']);
    assert_eq(2, count($vectors), 'two inputs must produce two vectors');
    assert_eq((int) $model['dimension'], count($vectors[0]), 'vector dimension must match embedding_models.dimension');
});

// ---------------------------------------------------------------------------
// 4. Worker <-> Converter
// ---------------------------------------------------------------------------
test('Converter: health reports required tools', function (): void {
    $health = (new ConverterClient())->health();
    assert_eq('ok', $health['status'] ?? null, 'converter health status must be ok');
});

test('Converter: plain text fixture produces text and metadata', function (): void {
    $result = (new ConverterClient())->convert('/srv/data/input/fixtures/sample.txt');
    assert_eq('ok', $result['status'] ?? null, 'conversion status must be ok');
    assert_true(strlen((string) $result['text']) > 0, 'converted text must not be empty');
    assert_eq('txt', $result['extension'] ?? null, 'extension must be txt');
});

test('Converter: PDF fixture produces text and page count', function (): void {
    $result = (new ConverterClient())->convert('/srv/data/input/fixtures/sample.pdf');
    assert_eq('ok', $result['status'] ?? null, 'PDF conversion status must be ok');
    assert_true(strlen((string) $result['text']) > 0, 'PDF text must not be empty');
    assert_true((int) ($result['page_count'] ?? 0) >= 1, 'PDF page_count must be >= 1');
});

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------
foreach ($GLOBALS['__tests'] as $case) {
    try {
        $case['fn']();
        $GLOBALS['__passed']++;
        fwrite(STDOUT, "ok   - {$case['name']}\n");
    } catch (Throwable $e) {
        $GLOBALS['__failed']++;
        fwrite(STDOUT, "FAIL - {$case['name']}\n");
        fwrite(STDOUT, '       ' . get_class($e) . ': ' . $e->getMessage() . "\n");
        fwrite(STDOUT, "       at {$e->getFile()}:{$e->getLine()}\n");
    }
}

$total = $GLOBALS['__passed'] + $GLOBALS['__failed'];
fwrite(STDOUT, "\n" . $GLOBALS['__passed'] . "/{$total} integration tests passed\n");

exit($GLOBALS['__failed'] > 0 ? 1 : 0);
