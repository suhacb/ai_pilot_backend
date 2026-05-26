<?php

namespace Tests\Unit;

use App\Services\Ingestion\HtmlParser;
use PHPUnit\Framework\TestCase;

class HtmlParserTest extends TestCase
{
    private HtmlParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new HtmlParser();
    }

    public function test_it_returns_empty_array_for_empty_html(): void
    {
        $sections = $this->parse('<html><body></body></html>');

        $this->assertSame([], $sections);
    }

    public function test_it_parses_headings_as_section_headings(): void
    {
        $html = <<<HTML
<html><body>
<h2>Člen 1 — Splošne določbe</h2>
<p>Vsebina prvega člena.</p>
<h2>Člen 2 — Opredelitev pojmov</h2>
<p>Vsebina drugega člena.</p>
</body></html>
HTML;

        $sections = $this->parse($html);

        $this->assertCount(2, $sections);
        $this->assertSame('Člen 1 — Splošne določbe', $sections[0]['heading']);
        $this->assertSame('Člen 2 — Opredelitev pojmov', $sections[1]['heading']);
    }

    public function test_it_groups_paragraphs_under_preceding_heading(): void
    {
        $html = <<<HTML
<html><body>
<h3>Člen 5</h3>
<p>Prvi odstavek.</p>
<p>Drugi odstavek.</p>
</body></html>
HTML;

        $sections = $this->parse($html);

        $this->assertCount(1, $sections);
        $this->assertStringContainsString('Prvi odstavek.', $sections[0]['body']);
        $this->assertStringContainsString('Drugi odstavek.', $sections[0]['body']);
    }

    public function test_it_places_content_before_first_heading_in_preamble_section(): void
    {
        $html = <<<HTML
<html><body>
<p>Uvodna vsebina zakona.</p>
<h2>Člen 1</h2>
<p>Vsebina člena.</p>
</body></html>
HTML;

        $sections = $this->parse($html);

        $this->assertCount(2, $sections);
        $this->assertNull($sections[0]['heading']);
        $this->assertStringContainsString('Uvodna vsebina zakona.', $sections[0]['body']);
    }

    public function test_it_strips_html_tags_from_body_content(): void
    {
        $html = <<<HTML
<html><body>
<h2>Člen 1</h2>
<p>Vsebina z <strong>poudarjenim</strong> besedilom in <a href="#">povezavo</a>.</p>
</body></html>
HTML;

        $sections = $this->parse($html);

        $this->assertStringNotContainsString('<strong>', $sections[0]['body']);
        $this->assertStringNotContainsString('<a ', $sections[0]['body']);
        $this->assertStringContainsString('poudarjenim', $sections[0]['body']);
    }

    public function test_it_handles_h1_through_h4_as_section_boundaries(): void
    {
        $html = <<<HTML
<html><body>
<h1>Naslov zakona</h1>
<p>Uvod.</p>
<h2>Poglavje I</h2>
<p>Splošne določbe.</p>
<h3>Člen 1</h3>
<p>Vsebina člena.</p>
<h4>Točka 1.1</h4>
<p>Podvsebina.</p>
</body></html>
HTML;

        $sections = $this->parse($html);

        $this->assertCount(4, $sections);
        $this->assertSame('Naslov zakona', $sections[0]['heading']);
        $this->assertSame('Poglavje I',   $sections[1]['heading']);
        $this->assertSame('Člen 1',       $sections[2]['heading']);
        $this->assertSame('Točka 1.1',    $sections[3]['heading']);
    }

    public function test_it_omits_sections_with_empty_body(): void
    {
        $html = <<<HTML
<html><body>
<h2>Člen 1</h2>
<h2>Člen 2</h2>
<p>Vsebina drugega člena.</p>
</body></html>
HTML;

        $sections = $this->parse($html);

        $this->assertCount(1, $sections);
        $this->assertSame('Člen 2', $sections[0]['heading']);
    }

    // -------------------------------------------------------------------------

    private function parse(string $html): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'html_');
        file_put_contents($tmp, $html);

        try {
            return $this->parser->parse($tmp);
        } finally {
            unlink($tmp);
        }
    }
}
