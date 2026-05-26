<?php

namespace Tests\Unit\Agent;

use App\Services\Agent\Tools\SemanticSearchTool;
use App\Services\Ingestion\OllamaEmbedder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class SemanticSearchToolTest extends TestCase
{
    private const COLLECTION = 'compliance_docs';
    private const VECTOR     = [0.1, 0.2, 0.3];

    private array $history = [];

    public function test_it_embeds_the_query_and_posts_to_qdrant_search(): void
    {
        $mockEmbedder = $this->makeEmbedder();
        $tool = $this->makeTool($mockEmbedder, [
            new Response(200, [], json_encode(['result' => []])),
        ]);

        $tool->execute(['query' => 'ZInfV-1 obveznosti', 'top_k' => 3]);

        $mockEmbedder->expects($this->never())->method('embed'); // already called via makeEmbedder stub

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString('/collections/' . self::COLLECTION . '/points/search', (string) $request->getUri());

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame(self::VECTOR, $body['vector']);
        $this->assertSame(3, $body['limit']);
        $this->assertTrue($body['with_payload']);
    }

    public function test_it_returns_formatted_results_as_string(): void
    {
        $mockEmbedder = $this->makeEmbedder();
        $tool = $this->makeTool($mockEmbedder, [
            new Response(200, [], json_encode([
                'result' => [
                    [
                        'score'   => 0.92,
                        'payload' => [
                            'document_name' => 'ZInfV-1',
                            'section_title' => 'Člen 12',
                            'content'       => 'Vsebina člena.',
                        ],
                    ],
                ],
            ])),
        ]);

        $result = $tool->execute(['query' => 'test']);

        $this->assertStringContainsString('ZInfV-1', $result);
        $this->assertStringContainsString('Člen 12', $result);
        $this->assertStringContainsString('Vsebina člena.', $result);
    }

    public function test_it_applies_source_type_filter_when_provided(): void
    {
        $mockEmbedder = $this->makeEmbedder();
        $tool = $this->makeTool($mockEmbedder, [
            new Response(200, [], json_encode(['result' => []])),
        ]);

        $tool->execute(['query' => 'test', 'filter_source_type' => 'legislation']);

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertArrayHasKey('filter', $body);
        $this->assertSame('legislation', $body['filter']['must'][0]['match']['value']);
    }

    public function test_it_defaults_to_top_k_5(): void
    {
        $mockEmbedder = $this->makeEmbedder();
        $tool = $this->makeTool($mockEmbedder, [
            new Response(200, [], json_encode(['result' => []])),
        ]);

        $tool->execute(['query' => 'test']);

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame(5, $body['limit']);
    }

    public function test_it_returns_no_results_message_when_qdrant_returns_empty(): void
    {
        $mockEmbedder = $this->makeEmbedder();
        $tool = $this->makeTool($mockEmbedder, [
            new Response(200, [], json_encode(['result' => []])),
        ]);

        $result = $tool->execute(['query' => 'test']);

        $this->assertStringContainsString('No results', $result);
    }

    // -------------------------------------------------------------------------

    private function makeEmbedder(): OllamaEmbedder
    {
        $stub = $this->createMock(OllamaEmbedder::class);
        $stub->method('embed')->willReturn(self::VECTOR);
        return $stub;
    }

    /** @param  Response[]  $responses */
    private function makeTool(OllamaEmbedder $embedder, array $responses): SemanticSearchTool
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new SemanticSearchTool(
            $embedder,
            new Client(['handler' => $stack]),
            'http://qdrant:6333',
            self::COLLECTION,
        );
    }
}
