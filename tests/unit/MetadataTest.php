<?php

declare(strict_types=1);

use App\Services\MilvusClient;
use App\Services\ProcessingService;

function guessMime(string $ext): string
{
    $method = new ReflectionMethod(ProcessingService::class, 'guessMime');

    return (string) $method->invoke(new ProcessingService(), $ext);
}

test('ProcessingService supports every declared document format', function (): void {
    $expected = ['pdf', 'docx', 'doc', 'odt', 'rtf', 'pptx', 'ppt', 'odp',
        'html', 'htm', 'epub', 'xlsx', 'xls', 'ods', 'csv', 'txt', 'md', 'markdown'];
    foreach ($expected as $ext) {
        assert_true(in_array($ext, ProcessingService::SUPPORTED_EXTENSIONS, true), "missing extension {$ext}");
    }
    assert_count(18, ProcessingService::SUPPORTED_EXTENSIONS);
});

test('ProcessingService::guessMime maps document formats', function (): void {
    assert_eq('application/pdf', guessMime('pdf'));
    assert_eq('application/msword', guessMime('doc'));
    assert_eq('application/vnd.openxmlformats-officedocument.wordprocessingml.document', guessMime('docx'));
    assert_eq('application/vnd.openxmlformats-officedocument.presentationml.presentation', guessMime('pptx'));
    assert_eq('application/vnd.ms-powerpoint', guessMime('ppt'));
    assert_eq('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', guessMime('xlsx'));
    assert_eq('application/vnd.ms-excel', guessMime('xls'));
    assert_eq('application/vnd.oasis.opendocument.text', guessMime('odt'));
    assert_eq('application/vnd.oasis.opendocument.spreadsheet', guessMime('ods'));
    assert_eq('application/vnd.oasis.opendocument.presentation', guessMime('odp'));
});

test('ProcessingService::guessMime maps text formats', function (): void {
    assert_eq('text/plain', guessMime('txt'));
    assert_eq('text/markdown', guessMime('md'));
    assert_eq('text/markdown', guessMime('markdown'));
    assert_eq('text/csv', guessMime('csv'));
    assert_eq('text/html', guessMime('html'));
    assert_eq('text/html', guessMime('htm'));
});

test('ProcessingService::guessMime falls back to octet-stream', function (): void {
    assert_eq('application/octet-stream', guessMime('xyz'));
});

test('MilvusClient::collectionFor sanitises model names', function (): void {
    assert_eq('docvec_qwen3_embedding_0_6b', MilvusClient::collectionFor('Qwen3-Embedding-0.6B'));
    assert_eq('docvec_qwen3_embedding_4b', MilvusClient::collectionFor('Qwen3-Embedding-4B'));
    assert_eq('docvec_default', MilvusClient::collectionFor('###'));
});
