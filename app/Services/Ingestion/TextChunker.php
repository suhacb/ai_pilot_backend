<?php

namespace App\Services\Ingestion;

class TextChunker
{
    private const TARGET_CHARS  = 1200;  // ~300 tokens — safe for mxbai-embed-large's 512-token limit with Slovenian text
    private const OVERLAP_CHARS = 120;   // ~30 tokens

    /**
     * Split an array of parsed sections into chunks with metadata.
     *
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
                    'token_count'   => (int) ceil(mb_strlen($content) / 4),
                ];
            }
        }

        return $result;
    }

    /**
     * Split a body of text into overlapping chunks.
     *
     * Strategy: split on paragraph boundaries first, then sentence boundaries,
     * then hard-cut as a last resort.
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
                // Emit current buffer, carry overlap into next chunk
                $chunks[] = $buffer;
                $tail = mb_substr($buffer, -self::OVERLAP_CHARS);
                $next = $tail . "\n\n" . $paragraph;

                if (mb_strlen($next) <= self::TARGET_CHARS) {
                    $buffer = $next;
                } else {
                    // Paragraph itself is too large — split it by sentences
                    $sentenceChunks = $this->splitBySentences($paragraph);
                    $sentenceChunks[0] = $tail . "\n\n" . $sentenceChunks[0];
                    $last = array_pop($sentenceChunks);
                    foreach ($sentenceChunks as $sc) {
                        $chunks[] = $sc;
                    }
                    $buffer = $last;
                }
            } else {
                // Buffer was empty and the paragraph alone exceeds the limit
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
                    $tail = mb_substr($buffer, -self::OVERLAP_CHARS);
                    $buffer = $tail . $sentence;
                } else {
                    // Single sentence exceeds limit — hard cut
                    $chunks[] = mb_substr($sentence, 0, self::TARGET_CHARS);
                    $buffer = mb_substr($sentence, self::TARGET_CHARS - self::OVERLAP_CHARS);
                }
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks ?: [$text];
    }
}
