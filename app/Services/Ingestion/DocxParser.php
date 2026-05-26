<?php

namespace App\Services\Ingestion;

use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;

class DocxParser
{
    /**
     * Parse a .docx file into an array of sections.
     *
     * Each section is: ['heading' => string|null, 'body' => string]
     * Sections with empty body are omitted.
     */
    public function parse(string $filePath): array
    {
        Settings::setZipClass(Settings::PCLZIP);

        $phpWord = IOFactory::load($filePath);

        $sections = [];
        $current = ['heading' => null, 'body' => ''];

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if ($this->isHeading($element)) {
                    if (trim($current['body']) !== '') {
                        $sections[] = $current;
                    }
                    $current = ['heading' => $this->extractText($element), 'body' => ''];
                } else {
                    $text = $this->extractText($element);
                    if ($text !== '') {
                        $current['body'] .= ($current['body'] !== '' ? "\n\n" : '') . $text;
                    }
                }
            }
        }

        if (trim($current['body']) !== '') {
            $sections[] = $current;
        }

        return $sections;
    }

    private function isHeading(AbstractElement $element): bool
    {
        if ($element instanceof Title) {
            return true;
        }

        $styleName = $this->getParagraphStyleName($element);

        return $styleName !== null && stripos($styleName, 'heading') !== false;
    }

    private function extractText(AbstractElement $element): string
    {
        if ($element instanceof Title) {
            $text = $element->getText();
            if (is_string($text)) {
                return $text;
            }
            if (is_array($text)) {
                return implode('', array_map(
                    fn($t) => is_string($t) ? $t : (method_exists($t, 'getText') ? $t->getText() : ''),
                    $text
                ));
            }
            return '';
        }

        if ($element instanceof Text) {
            return (string) $element->getText();
        }

        if ($element instanceof TextRun) {
            $parts = [];
            foreach ($element->getElements() as $child) {
                if ($child instanceof Text) {
                    $parts[] = $child->getText();
                }
            }
            return implode('', $parts);
        }

        return '';
    }

    private function getParagraphStyleName(AbstractElement $element): ?string
    {
        if (!method_exists($element, 'getParagraphStyle')) {
            return null;
        }

        $style = $element->getParagraphStyle();

        if (is_string($style)) {
            return $style;
        }

        if (is_object($style) && method_exists($style, 'getStyleName')) {
            return $style->getStyleName();
        }

        return null;
    }
}
