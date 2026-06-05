<?php

namespace App\Services\Ingestion;

class TextChunker
{
    private const TARGET_CHARS  = 800;  // ~400 tokens — Slovenian legal text tokenizes at ~2 chars/token with BERT WordPiece
    private const OVERLAP_CHARS = 80;   // ~40 tokens

    /**
     * @param  array<array{heading: string|null, body: string}>  $sections
     * @return array<array{document_name: string, source_type: string, section_title: string|null, chunk_index: int, content: string, content_hash: string, token_count: int}>
     */
    public function chunk(array $sections, string $documentName, string $sourceType): array
    {
        $result = [];
        $chunkIndex = 0;

        foreach ($sections as $section) {
            $body = trim($section['body']);
            if ($body === '') {
                continue;
            }

            foreach ($this->splitText($body) as $content) {
                $result[] = [
                    'document_name' => $documentName,
                    'source_type'   => $sourceType,
                    'section_title' => $section['heading'],
                    'chunk_index'   => $chunkIndex++,
                    'content'       => $content,
                    'content_hash'  => hash('sha256', $content),
                    'token_count'   => (int) ceil(mb_strlen($content) / 2),
                ];
            }
        }

        return $result;
    }

    /**
     * Split a body of text into overlapping chunks.
     * Strategy: paragraph boundaries → sentence boundaries → word-boundary hard cut.
     *
     * @return string[]
     */
    private function splitText(string $text): array
    {
        if (mb_strlen($text) <= self::TARGET_CHARS) {
            return [$text];
        }

        $paragraphs = array_values(array_filter(
            preg_split('/\n\n+/', $text),
            fn(string $p) => trim($p) !== ''
        ));

        $chunks = [];
        $buffer = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            $candidate = $buffer !== '' ? $buffer . "\n\n" . $paragraph : $paragraph;

            if (mb_strlen($candidate) <= self::TARGET_CHARS) {
                $buffer = $candidate;
                continue;
            }

            if ($buffer !== '') {
                $chunks[] = $buffer;
                $tail = mb_substr($buffer, $this->overlapPoint($buffer, self::OVERLAP_CHARS));
                $next = $tail . "\n\n" . $paragraph;

                if (mb_strlen($next) <= self::TARGET_CHARS) {
                    $buffer = $next;
                } else {
                    $sentenceChunks = $this->splitBySentences($paragraph);
                    $sentenceChunks[0] = $tail . "\n\n" . $sentenceChunks[0];
                    $last = array_pop($sentenceChunks);
                    foreach ($sentenceChunks as $sc) {
                        $chunks[] = $sc;
                    }
                    $buffer = $last;
                }
            } else {
                $sentenceChunks = $this->splitBySentences($paragraph);
                $last = array_pop($sentenceChunks);
                foreach ($sentenceChunks as $sc) {
                    $chunks[] = $sc;
                }
                $buffer = $last;
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks ?: [$text];
    }

    /**
     * Split a single paragraph by sentence boundaries with overlap.
     *
     * @return string[]
     */
    private function splitBySentences(string $text): array
    {
        $sentences = preg_split('/(?<=\. )/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $chunks = [];
        $buffer = '';

        foreach ($sentences as $sentence) {
            if (mb_strlen($buffer . $sentence) <= self::TARGET_CHARS) {
                $buffer .= $sentence;
            } else {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                    $tail   = mb_substr($buffer, $this->overlapPoint($buffer, self::OVERLAP_CHARS));
                    $buffer = $tail . $sentence;
                } else {
                    // Single sentence exceeds limit — hard cut at word boundaries
                    while (mb_strlen($sentence) > self::TARGET_CHARS) {
                        $cut    = $this->cutPoint($sentence, self::TARGET_CHARS);
                        $chunks[] = mb_substr($sentence, 0, $cut);
                        $advance  = $this->overlapPoint(mb_substr($sentence, 0, $cut), self::OVERLAP_CHARS);
                        $sentence = mb_substr($sentence, max(1, $advance));
                    }
                    $buffer = $sentence;
                }
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks ?: [$text];
    }

    /**
     * Index of the last word boundary (space) at or before $maxChars.
     * Falls back to $maxChars when no space exists (e.g. a URL or code token).
     */
    private function cutPoint(string $text, int $maxChars): int
    {
        $len = mb_strlen($text);
        if ($len <= $maxChars) {
            return $len;
        }
        $pos = mb_strrpos(mb_substr($text, 0, $maxChars), ' ');
        return ($pos !== false && $pos > 0) ? $pos : $maxChars;
    }

    /**
     * Start index for the overlap tail: the first word boundary at or after
     * (mb_strlen($text) - $overlapChars). Falls back to that position if no space is found.
     */
    private function overlapPoint(string $text, int $overlapChars): int
    {
        $len   = mb_strlen($text);
        $start = max(0, $len - $overlapChars);
        $pos   = mb_strpos($text, ' ', $start);
        return ($pos !== false) ? $pos + 1 : $start;
    }
}
