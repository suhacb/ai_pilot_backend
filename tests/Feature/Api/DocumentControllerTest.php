<?php

namespace Tests\Feature\Api;

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DocumentControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── GET /api/documents ───────────────────────────────────────────────────

    public function test_it_returns_list_of_documents(): void
    {
        Document::create([
            'name'        => 'ZInfV-1',
            'file_path'   => 'private/docs/ZInfV-1.docx',
            'source_type' => 'legislation',
            'status'      => 'indexed',
            'chunk_count' => 42,
        ]);

        $this->getJson('/api/documents')
             ->assertStatus(200)
             ->assertJsonCount(1)
             ->assertJsonPath('0.name', 'ZInfV-1')
             ->assertJsonPath('0.status', 'indexed');
    }

    public function test_it_returns_empty_array_when_no_documents(): void
    {
        $this->getJson('/api/documents')
             ->assertStatus(200)
             ->assertExactJson([]);
    }

    // ── POST /api/documents/ingest ───────────────────────────────────────────

    public function test_it_triggers_ingestion_for_valid_payload(): void
    {
        Artisan::shouldReceive('call')
               ->once()
               ->with('documents:ingest', [
                   'filename'      => 'ZInfV-1.docx',
                   '--source-type' => 'legislation',
               ])
               ->andReturn(0);

        $this->postJson('/api/documents/ingest', [
            'filename'    => 'ZInfV-1.docx',
            'source_type' => 'legislation',
        ])->assertStatus(200)
          ->assertJsonPath('message', 'Ingestion started.');
    }

    public function test_it_returns_422_when_filename_is_missing(): void
    {
        $this->postJson('/api/documents/ingest', ['source_type' => 'legislation'])
             ->assertStatus(422);
    }

    public function test_it_returns_422_when_source_type_is_invalid(): void
    {
        $this->postJson('/api/documents/ingest', [
            'filename'    => 'file.docx',
            'source_type' => 'invalid',
        ])->assertStatus(422);
    }

    public function test_it_returns_422_when_filename_has_wrong_extension(): void
    {
        $this->postJson('/api/documents/ingest', [
            'filename'    => 'file.pdf',
            'source_type' => 'legislation',
        ])->assertStatus(422);
    }
}
