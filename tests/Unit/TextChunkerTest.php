<?php

namespace Tests\Unit;

use App\Services\Ingestion\TextChunker;
use PHPUnit\Framework\TestCase;

class TextChunkerTest extends TestCase
{
    private TextChunker $chunker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chunker = new TextChunker();
    }

    public function test_it_produces_chunks_with_correct_metadata_fields(): void
    {
        $sections = [['heading' => 'Člen 1', 'body' => 'Kratko besedilo.']];
        $chunks = $this->chunker->chunk($sections, 'ZInfV-1', 'legislation');

        $this->assertNotEmpty($chunks);
        $chunk = $chunks[0];

        $this->assertArrayHasKey('document_name', $chunk);
        $this->assertArrayHasKey('source_type', $chunk);
        $this->assertArrayHasKey('section_title', $chunk);
        $this->assertArrayHasKey('chunk_index', $chunk);
        $this->assertArrayHasKey('content', $chunk);
        $this->assertArrayHasKey('content_hash', $chunk);
        $this->assertArrayHasKey('token_count', $chunk);

        $this->assertSame('ZInfV-1', $chunk['document_name']);
        $this->assertSame('legislation', $chunk['source_type']);
        $this->assertSame('Člen 1', $chunk['section_title']);
        $this->assertSame(0, $chunk['chunk_index']);
    }

    public function test_it_does_not_exceed_the_token_limit_per_chunk(): void
    {
        // ~6000 chars across 3 paragraphs — will produce multiple chunks
        $body = implode("\n\n", [
            str_repeat('Besedilo prvega odstavka. ', 90),   // ~2340 chars
            str_repeat('Besedilo drugega odstavka. ', 90),  // ~2430 chars
            str_repeat('Besedilo tretjega odstavka. ', 90), // ~2520 chars
        ]);

        $sections = [['heading' => 'Test', 'body' => $body]];
        $chunks = $this->chunker->chunk($sections, 'doc', 'legislation');

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            // 550 token ceiling (target 500 + small tolerance for overlap text)
            $this->assertLessThanOrEqual(550, $chunk['token_count'], "Chunk exceeded token limit");
        }
    }

    public function test_it_applies_overlap_between_consecutive_chunks(): void
    {
        // Paragraphs: p1 ~840 chars, p2 ~840 chars → fits in one chunk (~1680).
        // p3 ~840 chars would push it over 2000, forcing a split.
        $p1 = str_repeat('Paragraph one sentence here. ', 30);   // ~870 chars
        $p2 = str_repeat('Paragraph two sentence here. ', 30);   // ~870 chars
        $p3 = str_repeat('Paragraph three sentence here. ', 30); // ~930 chars

        $body = "$p1\n\n$p2\n\n$p3";
        $sections = [['heading' => 'Test', 'body' => $body]];
        $chunks = $this->chunker->chunk($sections, 'doc', 'legislation');

        $this->assertGreaterThanOrEqual(2, count($chunks));

        // The tail of chunk 0 must appear somewhere in chunk 1
        $tailOfChunk0 = substr($chunks[0]['content'], -200);
        $this->assertStringContainsString(
            trim($tailOfChunk0),
            $chunks[1]['content'],
            'Chunk 1 should contain the overlapping tail of chunk 0'
        );
    }

    public function test_it_carries_the_section_heading_onto_every_chunk(): void
    {
        $body = implode("\n\n", [
            str_repeat('A long sentence fills this paragraph. ', 60),
            str_repeat('Another long sentence fills this paragraph. ', 60),
            str_repeat('Yet another sentence fills this paragraph. ', 60),
        ]);

        $sections = [['heading' => 'Important Section', 'body' => $body]];
        $chunks = $this->chunker->chunk($sections, 'doc', 'legislation');

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertSame('Important Section', $chunk['section_title']);
        }
    }

    public function test_it_generates_unique_content_hash_per_chunk(): void
    {
        $body = implode("\n\n", [
            str_repeat('First paragraph text repeated. ', 60),
            str_repeat('Second paragraph text repeated. ', 60),
            str_repeat('Third paragraph text repeated. ', 60),
        ]);

        $sections = [['heading' => 'Test', 'body' => $body]];
        $chunks = $this->chunker->chunk($sections, 'doc', 'legislation');

        $hashes = array_column($chunks, 'content_hash');
        $this->assertSame(count($hashes), count(array_unique($hashes)), 'All content hashes must be unique');
    }

    public function test_it_handles_a_section_shorter_than_the_chunk_size(): void
    {
        $sections = [['heading' => 'Short', 'body' => 'Kratko besedilo.']];
        $chunks = $this->chunker->chunk($sections, 'doc', 'legislation');

        $this->assertCount(1, $chunks);
        $this->assertSame('Kratko besedilo.', $chunks[0]['content']);
    }

    public function test_chunk_index_is_sequential_across_sections(): void
    {
        $sections = [
            ['heading' => 'Section A', 'body' => 'Body A.'],
            ['heading' => 'Section B', 'body' => 'Body B.'],
            ['heading' => 'Section C', 'body' => 'Body C.'],
        ];
        $chunks = $this->chunker->chunk($sections, 'doc', 'legislation');

        $this->assertCount(3, $chunks);
        $this->assertSame(0, $chunks[0]['chunk_index']);
        $this->assertSame(1, $chunks[1]['chunk_index']);
        $this->assertSame(2, $chunks[2]['chunk_index']);
    }
}
