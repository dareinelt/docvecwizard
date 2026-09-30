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
                foreach ($this->hardSplit($segment) as $piece) {
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
            if (self::estimateTokens($sentence) > $this->hardMaxTokens) {
                $result = array_merge($result, $this->hardSplit($sentence));
            } else {
                $result[] = $sentence;
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function hardSplit(string $segment): array
    {
        $words = preg_split('/\s+/u', trim($segment)) ?: [];
        $pieces = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if (self::estimateTokens($candidate) > $this->hardMaxTokens && $current !== '') {
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
            $tokens += self::estimateTokens($w);
            if ($tokens > $tokenBudget && $kept !== []) {
                break;
            }
            array_unshift($kept, $w);
            if ($tokens > $tokenBudget) {
                break;
            }
        }

        return implode(' ', $kept);
    }
}
