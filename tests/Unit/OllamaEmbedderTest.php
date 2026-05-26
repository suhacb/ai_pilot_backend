<?php

namespace Tests\Unit;

use App\Services\Ingestion\OllamaEmbedder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class OllamaEmbedderTest extends TestCase
{
    private const BASE_URL = 'http://ollama:11434';
    private const MODEL = 'mxbai-embed-large';

    private array $history = [];

    public function test_it_posts_to_the_correct_endpoint_with_the_correct_model(): void
    {
        $vector = array_fill(0, 1024, 0.1);
        $embedder = $this->makeEmbedder([
            new Response(200, [], json_encode(['embeddings' => [$vector]])),
        ]);

        $embedder->embed('test text');

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::BASE_URL . '/api/embed', (string) $request->getUri());

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame(self::MODEL, $body['model']);
        $this->assertSame('test text', $body['input']);
    }

    public function test_it_returns_the_embedding_vector_as_a_flat_float_array(): void
    {
        $vector = [0.1, 0.2, 0.3, 0.4];
        $embedder = $this->makeEmbedder([
            new Response(200, [], json_encode(['embeddings' => [$vector]])),
        ]);

        $result = $embedder->embed('text');

        $this->assertSame($vector, $result);
    }

    public function test_it_throws_runtime_exception_on_non_200_response(): void
    {
        $embedder = $this->makeEmbedder([
            new Response(500, [], 'Internal Server Error'),
        ]);

        $this->expectException(\RuntimeException::class);

        $embedder->embed('text');
    }

    // -------------------------------------------------------------------------

    /** @param  Response[]  $responses */
    private function makeEmbedder(array $responses): OllamaEmbedder
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new OllamaEmbedder(
            new Client(['handler' => $stack]),
            self::BASE_URL,
            self::MODEL,
        );
    }
}
