<?php

namespace App\Services\Ingestion;

class HtmlParser
{
    private const HEADING_TAGS   = ['h1', 'h2', 'h3', 'h4'];
    private const PARAGRAPH_TAGS = ['p'];

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

        $tags   = implode('|//', array_merge(self::HEADING_TAGS, self::PARAGRAPH_TAGS));
        $nodes  = $xpath->query('//' . $tags);

        $sections       = [];
        $currentHeading = null;
        $currentBody    = '';

        foreach ($nodes as $node) {
            $tag  = strtolower($node->nodeName);
            $text = trim($node->textContent);

            if ($text === '') {
                continue;
            }

            if (in_array($tag, self::HEADING_TAGS, true)) {
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

        return $sections;
    }
}
