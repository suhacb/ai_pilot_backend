<?php

namespace Tests\Unit;

use App\Services\Ingestion\DocxParser;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PHPUnit\Framework\TestCase;

class DocxParserTest extends TestCase
{
    private DocxParser $parser;
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new DocxParser();
        $this->fixturePath = $this->createFixture();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (file_exists($this->fixturePath)) {
            unlink($this->fixturePath);
        }
    }

    public function test_it_parses_headings_into_separate_sections(): void
    {
        $sections = $this->parser->parse($this->fixturePath);

        $headings = array_column($sections, 'heading');
        $this->assertContains('First Heading', $headings);
        $this->assertContains('Second Heading', $headings);
    }

    public function test_it_captures_body_text_under_the_correct_heading(): void
    {
        $sections = $this->parser->parse($this->fixturePath);

        $first = $this->findByHeading($sections, 'First Heading');
        $this->assertNotNull($first);
        $this->assertStringContainsString('Body of first section', $first['body']);

        $second = $this->findByHeading($sections, 'Second Heading');
        $this->assertNotNull($second);
        $this->assertStringContainsString('Body of second section', $second['body']);
    }

    public function test_it_returns_null_heading_for_text_before_the_first_heading(): void
    {
        $sections = $this->parser->parse($this->fixturePath);

        $preamble = $this->findByHeading($sections, null);
        $this->assertNotNull($preamble);
        $this->assertStringContainsString('Preamble text', $preamble['body']);
    }

    public function test_it_skips_sections_with_empty_body(): void
    {
        $sections = $this->parser->parse($this->fixturePath);

        // 'Empty Heading' was added with no body text — must not appear in results
        $headings = array_column($sections, 'heading');
        $this->assertNotContains('Empty Heading', $headings);
    }

    // -------------------------------------------------------------------------

    private function createFixture(): string
    {
        Settings::setZipClass(Settings::PCLZIP);

        $phpWord = new PhpWord();
        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 14]);

        $section = $phpWord->addSection();
        $section->addText('Preamble text here.');
        $section->addTitle('First Heading', 1);
        $section->addText('Body of first section.');
        $section->addTitle('Second Heading', 1);
        $section->addText('Body of second section.');
        $section->addTitle('Empty Heading', 1);
        // intentionally no body text after this heading

        $path = sys_get_temp_dir() . '/docx_parser_test_' . uniqid() . '.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($path);

        return $path;
    }

    private function findByHeading(array $sections, ?string $heading): ?array
    {
        foreach ($sections as $section) {
            if ($section['heading'] === $heading) {
                return $section;
            }
        }
        return null;
    }
}
