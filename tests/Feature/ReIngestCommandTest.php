<?php

namespace Tests\Feature;

use App\Jobs\IngestDocumentJob;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Ingestion\QdrantStore;
use App\Services\Ingestion\ZincSearchStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReIngestCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_it_errors_when_filename_not_found_in_database(): void
    {
        $this->artisan('documents:reingest', ['filename' => 'nonexistent.docx'])
             ->assertExitCode(1);
    }

    public function test_it_queues_single_document_by_filename(): void
    {
        $document = Document::factory()->create([
            'name'        => 'my_policy',
            'file_path'   => 'docs/my_policy.docx',
            'source_type' => 'internal_policy',
            'status'      => 'indexed',
            'chunk_count' => 5,
        ]);

        DocumentChunk::factory()->count(2)->create(['document_id' => $document->id]);

        $this->mockQdrantAndZinc();

        $this->artisan('documents:reingest', ['filename' => 'my_policy.docx'])
             ->assertExitCode(0);

        Queue::assertPushed(IngestDocumentJob::class, function ($job) {
            return $job->filename === 'my_policy.docx' && $job->sourceType === 'internal_policy';
        });
    }

    public function test_it_wipes_chunks_before_queuing(): void
    {
        $document = Document::factory()->create([
            'name'        => 'my_policy',
            'file_path'   => 'docs/my_policy.docx',
            'source_type' => 'internal_policy',
            'status'      => 'indexed',
            'chunk_count' => 3,
        ]);

        DocumentChunk::factory()->count(3)->create(['document_id' => $document->id]);

        $this->mockQdrantAndZinc();

        $this->artisan('documents:reingest', ['filename' => 'my_policy.docx'])
             ->assertExitCode(0);

        $this->assertDatabaseCount('document_chunks', 0);
    }

    public function test_it_resets_document_status_to_pending(): void
    {
        $document = Document::factory()->create([
            'name'        => 'my_policy',
            'file_path'   => 'docs/my_policy.docx',
            'source_type' => 'internal_policy',
            'status'      => 'indexed',
            'chunk_count' => 3,
        ]);

        $this->mockQdrantAndZinc();

        $this->artisan('documents:reingest', ['filename' => 'my_policy.docx'])
             ->assertExitCode(0);

        $this->assertDatabaseHas('documents', [
            'id'     => $document->id,
            'status' => 'pending',
        ]);
    }

    public function test_it_queues_all_documents_with_zero_chunks_when_no_filename_given(): void
    {
        Document::factory()->create([
            'name'        => 'empty_doc',
            'file_path'   => 'docs/empty_doc.docx',
            'source_type' => 'internal_policy',
            'status'      => 'indexed',
            'chunk_count' => 0,
        ]);

        Document::factory()->create([
            'name'        => 'another_empty',
            'file_path'   => 'docs/another_empty.html',
            'source_type' => 'legislation',
            'status'      => 'indexed',
            'chunk_count' => 0,
        ]);

        // This one has chunks — should NOT be queued
        Document::factory()->create([
            'name'        => 'full_doc',
            'file_path'   => 'docs/full_doc.docx',
            'source_type' => 'internal_policy',
            'status'      => 'indexed',
            'chunk_count' => 10,
        ]);

        $this->mockQdrantAndZinc(times: 2);

        $this->artisan('documents:reingest')
             ->assertExitCode(0);

        Queue::assertPushed(IngestDocumentJob::class, 2);
        Queue::assertPushed(IngestDocumentJob::class, fn ($job) => $job->filename === 'empty_doc.docx');
        Queue::assertPushed(IngestDocumentJob::class, fn ($job) => $job->filename === 'another_empty.html');
        Queue::assertNotPushed(IngestDocumentJob::class, fn ($job) => $job->filename === 'full_doc.docx');
    }

    public function test_it_reports_nothing_to_reingest_when_no_zero_chunk_documents(): void
    {
        Document::factory()->create([
            'name'        => 'full_doc',
            'file_path'   => 'docs/full_doc.docx',
            'source_type' => 'internal_policy',
            'status'      => 'indexed',
            'chunk_count' => 5,
        ]);

        $this->artisan('documents:reingest')
             ->expectsOutputToContain('No documents')
             ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    // -------------------------------------------------------------------------

    private function mockQdrantAndZinc(int $times = 1): void
    {
        $qdrant = $this->createMock(QdrantStore::class);
        $qdrant->expects($this->exactly($times))->method('deleteByDocument');
        $this->app->instance(QdrantStore::class, $qdrant);

        $zinc = $this->createMock(ZincSearchStore::class);
        $zinc->expects($this->exactly($times))->method('deleteByDocument');
        $this->app->instance(ZincSearchStore::class, $zinc);
    }
}
