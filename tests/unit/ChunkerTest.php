<?php

declare(strict_types=1);

use App\Services\Chunker;

test('Chunker::estimateTokens returns 0 for empty text', function (): void {
    assert_eq(0, Chunker::estimateTokens(''));
    assert_eq(0, Chunker::estimateTokens("   \n  "));
});

test('Chunker::estimateTokens counts CJK characters individually', function (): void {
    // Four CJK characters => four tokens.
    assert_eq(4, Chunker::estimateTokens('你好世界'));
});

test('Chunker::estimateTokens groups Latin text at ~4 chars/token', function (): void {
    // "hello" (5 chars) => ceil(5/4) = 2.
    assert_eq(2, Chunker::estimateTokens('hello'));
});

test('Chunker::chunk returns no chunks for empty text', function (): void {
    assert_count(0, (new Chunker())->chunk(''));
});

test('Chunker::chunk returns one chunk for short text', function (): void {
    $chunks = (new Chunker())->chunk('Ein kurzer Absatz.');
    assert_count(1, $chunks);
    assert_eq('Ein kurzer Absatz.', $chunks[0]['text']);
});

test('Chunker::chunk splits long text into multiple chunks', function (): void {
    $text = '';
    for ($i = 0; $i < 400; $i++) {
        $text .= "Satz Nummer {$i} mit genügend Wörtern. ";
    }
    $chunks = (new Chunker(64, 16))->chunk($text);
    assert_true(count($chunks) > 1, 'expected more than one chunk');
});

test('Chunker::chunk keeps every token_count consistent', function (): void {
    $text = str_repeat('Wort ', 2000);
    $chunks = (new Chunker())->chunk($text);
    assert_true(count($chunks) > 0, 'expected chunks');
    foreach ($chunks as $chunk) {
        assert_eq(Chunker::estimateTokens($chunk['text']), $chunk['token_count']);
    }
});

test('Chunker::chunk is deterministic', function (): void {
    $text = str_repeat("Deterministischer Text mit Umlauten äöüß. ", 300);
    $a = (new Chunker())->chunk($text);
    $b = (new Chunker())->chunk($text);
    assert_eq($a, $b);
});

test('Chunker::chunk force-splits an overlong single segment', function (): void {
    // One enormous "word" beyond hard_max_tokens must be split into pieces.
    $text = str_repeat('abcdefgh', 1000);
    $chunks = (new Chunker(16, 4, 32))->chunk($text);
    assert_true(count($chunks) > 1, 'expected force-split into multiple chunks');
    foreach ($chunks as $chunk) {
        assert_true($chunk['token_count'] <= 32, 'hard-split pieces must respect hard max');
    }
});

test('Chunker::chunk preserves overlap across chunk boundaries', function (): void {
    $text = '';
    for ($i = 0; $i < 300; $i++) {
        $text .= "Einzigartiger Satz Nummer {$i} mit Inhalt. ";
    }
    $chunks = (new Chunker(64, 16))->chunk($text);
    // With overlap, the concatenated chunk text should contain the tail of the
    // previous chunk (words repeat), which we verify by checking for a shared word.
    assert_true(count($chunks) > 1, 'expected multiple chunks');
    $firstWords = explode(' ', $chunks[0]['text']);
    $tailWord = end($firstWords);
    assert_contains($tailWord, $chunks[1]['text']);
});

test('Chunker minTokens merges a tiny trailing remainder into the previous chunk', function (): void {
    // 28 estimated tokens; maxTokens 28 forces the 2-token tail into a second chunk.
    $a = str_repeat('alpha beta gamma delta ', 4);
    $tail = 'Ende.';
    $text = trim($a) . "\n\n" . $tail;

    $without = (new Chunker(28, 0, 100, 0))->chunk($text);
    assert_count(2, $without);
    assert_eq('Ende.', $without[1]['text']);

    $with = (new Chunker(28, 0, 100, 10))->chunk($text);
    assert_count(1, $with);
    assert_true(str_ends_with($with[0]['text'], "\n\nEnde."));
    assert_eq(trim($a) . "\n\nEnde.", $with[0]['text']);
});

test('Chunker minTokens does not duplicate the overlap when merging', function (): void {
    $a = str_repeat('alpha beta gamma delta ', 4);
    $text = trim($a) . "\n\nEnde.";
    $chunks = (new Chunker(28, 4, 100, 10))->chunk($text);
    assert_count(1, $chunks);
    assert_eq(1, substr_count($chunks[0]['text'], 'Ende.'));
    assert_eq(trim($a) . "\n\nEnde.", $chunks[0]['text']);
});

test('Chunker minTokens never exceeds the hard maximum', function (): void {
    $a = str_repeat('alpha beta gamma delta ', 4);
    $text = trim($a) . "\n\nEnde.";
    // hardMax equals maxTokens: merging would overflow, so the small chunk stays.
    $chunks = (new Chunker(28, 0, 28, 10))->chunk($text);
    assert_count(2, $chunks);
    foreach ($chunks as $c) {
        assert_true($c['token_count'] <= 28);
    }
});

test('Chunker minTokens leaves a sufficiently large last chunk alone', function (): void {
    $a = str_repeat('alpha beta gamma delta ', 4);
    $b = str_repeat('eins zwei drei vier ', 5);
    $chunks = (new Chunker(28, 0, 100, 10))->chunk(trim($a) . "\n\n" . trim($b));
    assert_count(2, $chunks);
});
