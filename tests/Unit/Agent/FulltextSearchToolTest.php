<?php

namespace Tests\Unit\Agent;

use App\Services\Agent\Tools\FulltextSearchTool;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class FulltextSearchToolTest extends TestCase
{
    private const INDEX    = 'compliance_docs';
    private const BASE_URL = 'http://zincsearch:4080';

    private array $history = [];

    public function test_it_posts_search_request_to_zincsearch(): void
    {
        $tool = $this->makeTool([
            new Response(200, [], json_encode(['hits' => ['hits' => []]])),
        ]);

        $tool->execute(['query' => 'člen 12', 'top_k' => 3]);

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];

        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString(self::INDEX . '/_search', (string) $request->getUri());

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('člen 12', $body['query']['term']);
        $this->assertSame(3, $body['max_results']);
    }

    public function test_it_defaults_to_top_k_5(): void
    {
        $tool = $this->makeTool([
            new Response(200, [], json_encode(['hits' => ['hits' => []]])),
        ]);

        $tool->execute(['query' => 'test']);

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame(5, $body['max_results']);
    }

    public function test_it_returns_formatted_results_as_string(): void
    {
        $tool = $this->makeTool([
            new Response(200, [], json_encode([
                'hits' => [
                    'hits' => [
                        [
                            '_source' => [
                                'document_name' => 'ZInfV-1',
                                'section_title' => 'Člen 12',
                                'content'       => 'Besedilo dvanajstega člena.',
                            ],
                        ],
                    ],
                ],
            ])),
        ]);

        $result = $tool->execute(['query' => 'člen 12']);

        $this->assertStringContainsString('ZInfV-1', $result);
        $this->assertStringContainsString('Člen 12', $result);
        $this->assertStringContainsString('Besedilo dvanajstega člena.', $result);
    }

    public function test_it_returns_no_results_message_when_empty(): void
    {
        $tool = $this->makeTool([
            new Response(200, [], json_encode(['hits' => ['hits' => []]])),
        ]);

        $result = $tool->execute(['query' => 'nothing']);

        $this->assertStringContainsString('No results', $result);
    }

    // -------------------------------------------------------------------------

    /** @param  Response[]  $responses */
    private function makeTool(array $responses): FulltextSearchTool
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new FulltextSearchTool(
            new Client(['handler' => $stack]),
            self::BASE_URL,
            self::INDEX,
            'admin',
            'secret',
        );
    }
}
