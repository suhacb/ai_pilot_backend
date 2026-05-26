<?php

namespace Tests\Feature;

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

class IngestCommandTest extends TestCase
{
    use RefreshDatabase;

    private const DOCS_PATH    = 'private/docs';
    private const FILENAME     = 'test_fixture.docx';
    private const HTML_FILENAME = 'test_fixture.html';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->putFixtureOnDisk();
        $this->putHtmlFixtureOnDisk();
        $this->bindMockedServices(chunkCount: 2);
    }

    public function test_it_exits_with_error_when_file_does_not_exist(): void
    {
        $this->artisan('documents:ingest', ['filename' => 'nonexistent.docx'])
             ->assertExitCode(1);
    }

    public function test_it_exits_with_error_when_source_type_is_invalid(): void
    {
        $this->artisan('documents:ingest', [
            'filename'      => self::FILENAME,
            '--source-type' => 'invalid_type',
        ])->assertExitCode(1);
    }

    public function test_it_exits_with_error_when_filename_has_wrong_extension(): void
    {
        $this->artisan('documents:ingest', ['filename' => 'document.pdf'])
             ->assertExitCode(1);
    }

    public function test_it_ingests_an_html_file(): void
    {
        $this->artisan('documents:ingest', [
            'filename'      => self::HTML_FILENAME,
            '--source-type' => 'legislation',
        ])->assertExitCode(0);
    }

    public function test_it_outputs_progress_lines_during_ingestion(): void
    {
        $this->artisan('documents:ingest', ['filename' => self::FILENAME])
             ->expectsOutputToContain('Chunk 1/')
             ->assertExitCode(0);
    }

    public function test_it_exits_zero_on_success(): void
    {
        $this->artisan('documents:ingest', [
            'filename'      => self::FILENAME,
            '--source-type' => 'legislation',
        ])->assertExitCode(0);
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

    private function putHtmlFixtureOnDisk(): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html>
<body>
<h2>Člen 1 — Splošne določbe</h2>
<p>Ta zakon ureja varnost informacij v Republiki Sloveniji.</p>
<h2>Člen 2 — Opredelitev pojmov</h2>
<p>V tem zakonu se uporabljajo naslednji pojmi.</p>
</body>
</html>
HTML;

        Storage::disk('local')->put(
            self::DOCS_PATH . '/' . self::HTML_FILENAME,
            $html
        );
    }

    private function bindMockedServices(int $chunkCount): void
    {
        $vector = array_fill(0, 1024, 0.1);

        $ollamaResponses = array_fill(
            0,
            $chunkCount,
            new Response(200, [], json_encode(['embeddings' => [$vector]]))
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
