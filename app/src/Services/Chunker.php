<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Pure, deterministic text chunker. Token counts are estimates (no external
 * tokenizer) based on word lengths, which is adequate for CJK and long German
 * compound words alike.
 */
final class Chunker
{
    public function __construct(
        private readonly int $maxTokens = 512,
        private readonly int $overlapTokens = 64,
        private readonly int $hardMaxTokens = 1024,
    ) {
    }

    /**
     * Estimate token count of a text using a conservative per-word heuristic.
     */
    public static function estimateTokens(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        $tokens = 0;
        $words = preg_split('/\s+/u', $text);
        foreach ($words ?: [] as $word) {
            $len = mb_strlen($word);
            if ($len === 0) {
                continue;
            }
            // CJK characters count as one token each; otherwise ~4 chars/token.
            if (preg_match('/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u', $word) === 1) {
                $tokens += $len;
            } else {
                $tokens += (int) max(1, (int) ceil($len / 4));
            }
        }

        return max(1, $tokens);
    }

    /**
     * Split text into overlapping chunks.
     *
     * @return list<array{text:string,token_count:int}>
     */
    public function chunk(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $segments = $this->segments($text);

        $chunks = [];
        $current = '';
        $currentTokens = 0;

        foreach ($segments as $segment) {
            $segTokens = self::estimateTokens($segment);
            if ($segTokens === 0) {
                continue;
            }
            // A single segment larger than the hard limit must be force-split.
            if ($segTokens > $this->hardMaxTokens) {
                if ($current !== '') {
                    $chunks[] = $this->makeChunk($current);
                    $current = '';
                    $currentTokens = 0;
                }
                foreach ($this->hardSplit($segment, $this->hardMaxTokens) as $piece) {
                    $chunks[] = $this->makeChunk($piece);
                }
                continue;
            }
            if ($currentTokens + $segTokens > $this->maxTokens && $current !== '') {
                $chunks[] = $this->makeChunk($current);
                // Keep overlap tail for continuity.
                $overlap = $this->tail($current, $this->overlapTokens);
                $current = $overlap;
                $currentTokens = self::estimateTokens($overlap);
            }
            $current = $current === '' ? $segment : $current . "\n\n" . $segment;
            $currentTokens = self::estimateTokens($current);
        }
        if (trim($current) !== '') {
            $chunks[] = $this->makeChunk($current);
        }

        return $chunks;
    }

    /** @return list<string> */
    private function segments(string $text): array
    {
        $paragraphs = preg_split('/\n\s*\n/u', $text) ?: [$text];
        $segments = [];
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            if (self::estimateTokens($paragraph) > $this->maxTokens) {
                $segments = array_merge($segments, $this->splitSentences($paragraph));
            } else {
                $segments[] = $paragraph;
            }
        }

        return $segments;
    }

    /** @return list<string> */
    private function splitSentences(string $paragraph): array
    {
        $sentences = preg_split('/(?<=[.!?;:])\s+/u', $paragraph) ?: [$paragraph];
        $result = [];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }
            // Cap every segment at maxTokens so the packer in chunk() can
            // accumulate them (with overlap) without exceeding hardMaxTokens.
            if (self::estimateTokens($sentence) > $this->maxTokens) {
                $result = array_merge($result, $this->hardSplit($sentence, $this->maxTokens));
            } else {
                $result[] = $sentence;
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function hardSplit(string $segment, int $limitTokens): array
    {
        $words = preg_split('/\s+/u', trim($segment)) ?: [];
        $pieces = [];
        $current = '';
        foreach ($words as $word) {
            // A single "word" with no whitespace can still exceed the limit
            // (e.g. a huge base64 blob or URL). Character-split it directly.
            if (self::estimateTokens($word) > $limitTokens) {
                if ($current !== '') {
                    $pieces[] = $current;
                    $current = '';
                }
                $pieces = array_merge($pieces, $this->hardSplitWord($word, $limitTokens));
                continue;
            }
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if (self::estimateTokens($candidate) > $limitTokens && $current !== '') {
                $pieces[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * Split an over-long, whitespace-free token into character-level pieces that
     * each respect the given token limit.
     *
     * @return list<string>
     */
    private function hardSplitWord(string $word, int $limitTokens): array
    {
        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $pieces = [];
        $current = '';
        foreach ($chars as $char) {
            $candidate = $current . $char;
            if (self::estimateTokens($candidate) > $limitTokens && $current !== '') {
                $pieces[] = $current;
                $current = $char;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /** @return array{text:string,token_count:int} */
    private function makeChunk(string $text): array
    {
        $text = trim($text);

        return ['text' => $text, 'token_count' => self::estimateTokens($text)];
    }

    /** Return the last ~$tokenBudget tokens of a text as words. */
    private function tail(string $text, int $tokenBudget): string
    {
        if ($tokenBudget <= 0) {
            return '';
        }
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $kept = [];
        $tokens = 0;
        for ($i = count($words) - 1; $i >= 0; $i--) {
            $w = $words[$i];
            $wTokens = self::estimateTokens($w);
            // A single whitespace-free word can exceed the whole budget; take
            // only its trailing characters so the overlap never overruns.
            if ($wTokens >= $tokenBudget) {
                array_unshift($kept, $this->tailChars($w, $tokenBudget));
                break;
            }
            if ($tokens + $wTokens > $tokenBudget && $kept !== []) {
                break;
            }
            array_unshift($kept, $w);
            $tokens += $wTokens;
            if ($tokens >= $tokenBudget) {
                break;
            }
        }

        return implode(' ', $kept);
    }

    /** Return the trailing characters of a word up to $tokenBudget tokens. */
    private function tailChars(string $word, int $tokenBudget): string
    {
        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = [];
        for ($i = count($chars) - 1; $i >= 0; $i--) {
            $candidate = $chars[$i] . implode('', $kept);
            if (self::estimateTokens($candidate) > $tokenBudget) {
                break;
            }
            array_unshift($kept, $chars[$i]);
            if (self::estimateTokens($candidate) >= $tokenBudget) {
                break;
            }
        }

        return implode('', $kept);
    }
}
