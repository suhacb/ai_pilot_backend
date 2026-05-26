<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Ingestion\OllamaEmbedder;
use App\Services\Ingestion\QdrantStore;
use App\Services\Ingestion\ZincSearchStore;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use Tests\TestCase;

class DocumentIngestionTest extends TestCase
{
    use RefreshDatabase;

    private const DOCS_PATH  = 'private/docs';
    private const FILENAME   = 'test_fixture.docx';
    private const CHUNK_COUNT = 2;
    private const VECTOR     = [0.1, 0.2, 0.3];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->putFixtureOnDisk();
    }

    public function test_it_creates_document_record_with_correct_status_transitions(): void
    {
        $this->bindMockedServices();

        $this->artisan('documents:ingest', ['filename' => self::FILENAME]);

        $doc = Document::first();
        $this->assertNotNull($doc);
        $this->assertSame('indexed', $doc->status);
        $this->assertNotNull($doc->ingested_at);
    }

    public function test_it_creates_the_correct_number_of_document_chunk_records(): void
    {
        $this->bindMockedServices();

        $this->artisan('documents:ingest', ['filename' => self::FILENAME]);

        $this->assertDatabaseCount('document_chunks', self::CHUNK_COUNT);
    }

    public function test_it_stores_the_correct_content_hash_on_each_chunk(): void
    {
        $this->bindMockedServices();

        $this->artisan('documents:ingest', ['filename' => self::FILENAME]);

        DocumentChunk::all()->each(function (DocumentChunk $chunk) {
            $this->assertSame(
                hash('sha256', $chunk->content),
                $chunk->content_hash,
                'content_hash must be SHA-256 of content'
            );
        });
    }

    public function test_it_skips_re_ingesting_a_chunk_with_a_known_content_hash(): void
    {
        // First run
        $this->bindMockedServices();
        $this->artisan('documents:ingest', ['filename' => self::FILENAME]);
        $this->assertDatabaseCount('document_chunks', self::CHUNK_COUNT);

        // Second run — only the vector-size embed + collection check happen;
        // all chunks are skipped because their hashes already exist
        $this->bindMockedServices(skipChunkCalls: true);
        $this->artisan('documents:ingest', ['filename' => self::FILENAME]);

        $this->assertDatabaseCount('document_chunks', self::CHUNK_COUNT);
    }

    public function test_it_sets_document_status_to_failed_when_embedder_throws(): void
    {
        $mockEmbedder = $this->createMock(OllamaEmbedder::class);
        $mockEmbedder->method('embed')
                     ->willThrowException(new \RuntimeException('Ollama unreachable'));

        $this->app->instance(OllamaEmbedder::class, $mockEmbedder);
        $this->app->instance(QdrantStore::class, $this->createMock(QdrantStore::class));
        $this->app->instance(ZincSearchStore::class, $this->createMock(ZincSearchStore::class));

        $this->artisan('documents:ingest', ['filename' => self::FILENAME])
             ->assertFailed();

        $this->assertSame('failed', Document::first()->status);
    }

    public function test_it_stores_chunk_count_on_the_document_after_successful_ingestion(): void
    {
        $this->bindMockedServices();

        $this->artisan('documents:ingest', ['filename' => self::FILENAME]);

        $this->assertSame(self::CHUNK_COUNT, Document::first()->chunk_count);
    }

    // -------------------------------------------------------------------------

    private function putFixtureOnDisk(): void
    {
        Settings::setZipClass(Settings::PCLZIP);

        $phpWord = new PhpWord();
        $phpWord->addTitleStyle(1, ['bold' => true]);
        $section = $phpWord->addSection();
        $section->addTitle('Člen 1', 1);
        $section->addText('Kratko besedilo prvega člena zakona.');
        $section->addTitle('Člen 2', 1);
        $section->addText('Kratko besedilo drugega člena zakona.');

        $tmp = tempnam(sys_get_temp_dir(), 'docx_');
        IOFactory::createWriter($phpWord, 'Word2007')->save($tmp);

        Storage::disk('local')->put(
            self::DOCS_PATH . '/' . self::FILENAME,
            file_get_contents($tmp)
        );

        unlink($tmp);
    }

    /**
     * @param  bool  $skipChunkCalls  Queue only the embed + collection-check responses,
     *                                 not the per-chunk upsert responses.
     */
    private function bindMockedServices(bool $skipChunkCalls = false): void
    {
        $chunkCount = $skipChunkCalls ? 0 : self::CHUNK_COUNT;

        $ollamaResponses = array_fill(
            0,
            $skipChunkCalls ? 1 : self::CHUNK_COUNT,
            new Response(200, [], json_encode(['embeddings' => [self::VECTOR]]))
        );

        $qdrantResponses = array_merge(
            [
                new Response(404, [], '{}'),
                new Response(200, [], '{"result": true}'),
            ],
            array_fill(0, $chunkCount, new Response(200, [], '{"result": {"status": "ok"}}'))
        );

        $zincResponses = array_fill(
            0,
            $chunkCount,
            new Response(200, [], '{"id": "ok"}')
        );

        $this->app->instance(OllamaEmbedder::class, new OllamaEmbedder(
            $this->mockClient($ollamaResponses), 'http://ollama', 'test-model',
        ));

        $this->app->instance(QdrantStore::class, new QdrantStore(
            $this->mockClient($qdrantResponses), 'http://qdrant', 'test-collection',
        ));

        $this->app->instance(ZincSearchStore::class, new ZincSearchStore(
            $this->mockClient($zincResponses), 'http://zinc', 'test-index', 'user', 'pass',
        ));
    }

    private function mockClient(array $responses): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        return new Client(['handler' => $stack]);
    }
}
