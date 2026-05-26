<?php

namespace Tests\Unit\Agent;

use App\Services\Agent\Tools\WebSearchTool;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class WebSearchToolTest extends TestCase
{
    private const BASE_URL = 'http://searxng:8080';

    private array $history = [];

    public function test_it_sends_get_request_to_searxng_with_json_format(): void
    {
        $tool = $this->makeTool([
            new Response(200, [], json_encode(['results' => []])),
        ]);

        $tool->execute(['query' => 'ZInfV-1 varnostni incident']);

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];

        $this->assertSame('GET', $request->getMethod());

        $uri = (string) $request->getUri();
        $this->assertStringContainsString('/search', $uri);
        $this->assertStringContainsString('format=json', $uri);
        $this->assertStringContainsString('ZInfV-1', $uri);
    }

    public function test_it_returns_formatted_results_as_string(): void
    {
        $tool = $this->makeTool([
            new Response(200, [], json_encode([
                'results' => [
                    [
                        'title'   => 'ZInfV-1 — Uradni list',
                        'url'     => 'https://example.si/zinf',
                        'content' => 'Zakon o informacijski varnosti...',
                    ],
                ],
            ])),
        ]);

        $result = $tool->execute(['query' => 'ZInfV-1']);

        $this->assertStringContainsString('ZInfV-1 — Uradni list', $result);
        $this->assertStringContainsString('https://example.si/zinf', $result);
        $this->assertStringContainsString('Zakon o informacijski varnosti', $result);
    }

    public function test_it_returns_no_results_message_when_empty(): void
    {
        $tool = $this->makeTool([
            new Response(200, [], json_encode(['results' => []])),
        ]);

        $result = $tool->execute(['query' => 'nothing']);

        $this->assertStringContainsString('No results', $result);
    }

    // -------------------------------------------------------------------------

    /** @param  Response[]  $responses */
    private function makeTool(array $responses): WebSearchTool
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new WebSearchTool(
            new Client(['handler' => $stack]),
            self::BASE_URL,
        );
    }
}
