<?php

namespace Tests\Feature\Agent;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Agent\Tools\GetDocumentTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetDocumentToolTest extends TestCase
{
    use RefreshDatabase;

    private GetDocumentTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new GetDocumentTool();
        $this->seedChunks();
    }

    public function test_it_returns_chunks_for_a_document_by_name(): void
    {
        $result = $this->tool->execute(['document_name' => 'ZInfV-1']);

        $this->assertStringContainsString('ZInfV-1', $result);
        $this->assertStringContainsString('Vsebina prvega člena.', $result);
        $this->assertStringContainsString('Vsebina drugega člena.', $result);
    }

    public function test_it_returns_chunks_ordered_by_chunk_index(): void
    {
        $result = $this->tool->execute(['document_name' => 'ZInfV-1']);

        $pos1 = strpos($result, 'Vsebina prvega člena.');
        $pos2 = strpos($result, 'Vsebina drugega člena.');

        $this->assertLessThan($pos2, $pos1, 'Chunks should appear in chunk_index order');
    }

    public function test_it_filters_by_section_title_when_provided(): void
    {
        $result = $this->tool->execute([
            'document_name' => 'ZInfV-1',
            'section_title' => 'Člen 1',
        ]);

        $this->assertStringContainsString('Vsebina prvega člena.', $result);
        $this->assertStringNotContainsString('Vsebina drugega člena.', $result);
    }

    public function test_it_returns_not_found_message_when_document_does_not_exist(): void
    {
        $result = $this->tool->execute(['document_name' => 'Nonexistent']);

        $this->assertStringContainsString('not found', strtolower($result));
    }

    // -------------------------------------------------------------------------

    private function seedChunks(): void
    {
        $doc = Document::create([
            'name'        => 'ZInfV-1',
            'file_path'   => 'private/docs/ZInfV-1.docx',
            'source_type' => 'legislation',
            'status'      => 'indexed',
        ]);

        DocumentChunk::create([
            'document_id'   => $doc->id,
            'qdrant_id'     => 'uuid-1',
            'chunk_index'   => 0,
            'section_title' => 'Člen 1',
            'content'       => 'Vsebina prvega člena.',
            'content_hash'  => hash('sha256', 'Vsebina prvega člena.'),
            'token_count'   => 5,
        ]);

        DocumentChunk::create([
            'document_id'   => $doc->id,
            'qdrant_id'     => 'uuid-2',
            'chunk_index'   => 1,
            'section_title' => 'Člen 2',
            'content'       => 'Vsebina drugega člena.',
            'content_hash'  => hash('sha256', 'Vsebina drugega člena.'),
            'token_count'   => 5,
        ]);
    }
}
