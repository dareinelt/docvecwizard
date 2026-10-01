<?php

declare(strict_types=1);

use App\Services\Chunker;
use App\Services\ProcessingService;

test('chunkPages: PDF text with form feeds is chunked per page and never spans pages', function (): void {
    $chunker = new Chunker(512, 64, 1024, 0);
    $text = "Seite eins Text.\fSeite zwei Text.\fSeite drei Text.";
    $chunks = ProcessingService::chunkPages($chunker, $text, 3, true);

    assert_count(3, $chunks);
    foreach ($chunks as $i => $chunk) {
        assert_eq($i, $chunk['chunk_index'], 'chunk_index is sequential');
        assert_eq($i + 1, $chunk['page_start']);
        assert_eq($i + 1, $chunk['page_end']);
        assert_false(str_contains($chunk['text'], "\f"), 'no page marker inside chunk');
    }
    assert_eq('Seite zwei Text.', $chunks[1]['text']);
});

test('chunkPages: empty PDF pages produce no chunks but keep page numbering', function (): void {
    $chunker = new Chunker(512, 64, 1024, 0);
    $chunks = ProcessingService::chunkPages($chunker, "Erste.\f\f\fVierte.", 4, true);

    assert_count(2, $chunks);
    assert_eq(1, $chunks[0]['page_start']);
    assert_eq(4, $chunks[1]['page_start']);
    assert_eq(1, $chunks[1]['chunk_index']);
});

test('chunkPages: a long PDF page is split into several chunks of that page', function (): void {
    $chunker = new Chunker(32, 8, 64, 0);
    $long = str_repeat('wort ', 60); // ~ 60 tokens > 32
    $chunks = ProcessingService::chunkPages($chunker, "kurz\f" . $long, 2, true);

    assert_true(count($chunks) >= 2, 'expected several chunks for page 2');
    assert_eq(1, $chunks[0]['page_start']);
    foreach (array_slice($chunks, 1) as $chunk) {
        assert_eq(2, $chunk['page_start'], 'all later chunks belong to page 2');
        assert_eq(2, $chunk['page_end']);
    }
});

test('chunkPages: non-PDF text is chunked as a whole and attributed to max(1, pageCount)', function (): void {
    $chunker = new Chunker(512, 64, 1024, 0);
    $text = "Absatz eins.\n\nAbsatz zwei.";

    $chunks = ProcessingService::chunkPages($chunker, $text, 0, false);
    assert_count(1, $chunks);
    assert_eq(1, $chunks[0]['page_start']);
    assert_eq(1, $chunks[0]['page_end']);

    $chunks = ProcessingService::chunkPages($chunker, $text, 7, false);
    assert_eq(7, $chunks[0]['page_start']);
});

test('chunkPages: form feeds in non-PDF text are not treated as page breaks', function (): void {
    $chunker = new Chunker(512, 64, 1024, 0);
    $chunks = ProcessingService::chunkPages($chunker, "a\fb", 1, false);

    assert_count(1, $chunks);
    assert_eq(1, $chunks[0]['page_end']);
});

test('chunkPages: empty text yields no chunks', function (): void {
    $chunker = new Chunker();
    assert_count(0, ProcessingService::chunkPages($chunker, '', 3, true));
    assert_count(0, ProcessingService::chunkPages($chunker, '', 0, false));
});
