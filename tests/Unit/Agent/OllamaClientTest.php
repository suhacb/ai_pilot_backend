<?php

namespace Tests\Unit\Agent;

use App\Services\Agent\OllamaClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class OllamaClientTest extends TestCase
{
    private const BASE_URL = 'http://ollama:11434';
    private const MODEL    = 'gemma4:27b';

    private array $history = [];

    public function test_it_posts_to_the_generate_endpoint_with_correct_payload(): void
    {
        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'model'    => self::MODEL,
                'response' => '{"action":"finish"}',
                'done'     => true,
            ])),
        ]);

        $client->generate('My prompt', self::MODEL);

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::BASE_URL . '/api/generate', (string) $request->getUri());

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame(self::MODEL, $body['model']);
        $this->assertSame('My prompt', $body['prompt']);
        $this->assertFalse($body['stream']);
        $this->assertSame('json', $body['format']);
    }

    public function test_it_returns_the_response_field_from_the_ollama_payload(): void
    {
        $expected = '{"reasoning":"test","action":"finish","parameters":{"final_answer":"ok"}}';

        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'model'    => self::MODEL,
                'response' => $expected,
                'done'     => true,
            ])),
        ]);

        $result = $client->generate('prompt', self::MODEL);

        $this->assertSame($expected, $result);
    }

    public function test_it_throws_runtime_exception_on_http_error(): void
    {
        $client = $this->makeClient([
            new Response(500, [], 'Internal Server Error'),
        ]);

        $this->expectException(\RuntimeException::class);

        $client->generate('prompt', self::MODEL);
    }

    // -------------------------------------------------------------------------

    /** @param  Response[]  $responses */
    private function makeClient(array $responses): OllamaClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new OllamaClient(
            new Client(['handler' => $stack]),
            self::BASE_URL,
        );
    }
}
