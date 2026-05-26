<?php

namespace App\Services\Ingestion;

class HtmlParser
{
    /** Standard HTML heading tags — used as section boundaries. */
    private const HEADING_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    /**
     * CSS class words whose elements start a new section (heading boundary).
     *
     * Covers two HTML sources used in this project:
     *  - EUR-Lex OJ HTML  (eli-main-title, eli-title, oj-ti-art)
     *  - PISRS Slovenian legal portal  (naslov, poglavje, clen)
     */
    private const HEADING_CLASSES = [
        // EUR-Lex
        'eli-main-title',
        'eli-title',
        'oj-ti-art',
        // PISRS
        'naslov',
        'poglavje',
        'clen',
    ];

    /**
     * CSS class words to skip entirely (decorative / redundant).
     */
    private const SKIP_CLASSES = [
        'oj-sti-art',   // EUR-Lex article subject line — duplicate of oj-ti-art
    ];

    /**
     * Parse an HTML file into an array of sections.
     *
     * Each section is: ['heading' => string|null, 'body' => string]
     * Sections with empty body are omitted.
     */
    public function parse(string $filePath): array
    {
        $html = file_get_contents($filePath);

        $dom = new \DOMDocument();
        @$dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $xpath = new \DOMXPath($dom);

        // Select all heading tags and every <p> in document order.
        // Classification (heading vs body vs skip) happens in the loop via CSS class inspection,
        // so this works regardless of which HTML source the file comes from.
        $nodes = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6|//p');

        $sections       = [];
        $currentHeading = null;
        $currentBody    = '';

        foreach ($nodes as $node) {
            $tag   = strtolower($node->nodeName);
            $class = $node->attributes?->getNamedItem('class')?->value ?? '';
            $text  = trim($node->textContent);

            if ($text === '') {
                continue;
            }

            if ($this->shouldSkip($class)) {
                continue;
            }

            if ($this->isHeading($tag, $class)) {
                if (trim($currentBody) !== '') {
                    $sections[] = ['heading' => $currentHeading, 'body' => trim($currentBody)];
                }
                $currentHeading = $text;
                $currentBody    = '';
            } else {
                $currentBody .= $text . "\n\n";
            }
        }

        if (trim($currentBody) !== '') {
            $sections[] = ['heading' => $currentHeading, 'body' => trim($currentBody)];
        }

        // Fallback for poorly structured HTML (e.g. PDF-converted pages where text
        // is scattered across <a> tags with no <p> or heading elements).
        // Extract all visible text from <body> as one unstructured section so the
        // chunker can still split it by size.
        if (empty($sections)) {
            $body = $xpath->query('//body');
            $text = $body->length > 0 ? trim($body->item(0)->textContent) : '';

            // Collapse runs of whitespace / blank lines to single newlines
            $text = preg_replace('/[ \t]+/', ' ', $text);
            $text = preg_replace('/\n{3,}/', "\n\n", $text);
            $text = trim($text);

            if ($text !== '') {
                $sections[] = ['heading' => null, 'body' => $text];
            }
        }

        return $sections;
    }

    private function isHeading(string $tag, string $class): bool
    {
        if (in_array($tag, self::HEADING_TAGS, true)) {
            return true;
        }

        $words = $this->classWords($class);

        foreach (self::HEADING_CLASSES as $headingClass) {
            if (in_array($headingClass, $words, true)) {
                return true;
            }
        }

        return false;
    }

    private function shouldSkip(string $class): bool
    {
        $words = $this->classWords($class);

        foreach (self::SKIP_CLASSES as $skipClass) {
            if (in_array($skipClass, $words, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    private function classWords(string $class): array
    {
        return array_filter(explode(' ', $class));
    }
}
