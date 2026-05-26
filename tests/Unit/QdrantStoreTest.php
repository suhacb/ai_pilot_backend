<?php

namespace Tests\Unit;

use App\Services\Ingestion\QdrantStore;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class QdrantStoreTest extends TestCase
{
    private const BASE_URL    = 'http://qdrant:6333';
    private const COLLECTION  = 'compliance_docs';

    private array $history = [];

    public function test_it_creates_the_collection_when_it_does_not_exist(): void
    {
        // GET → 404 (not found), then PUT → 200 (created)
        $store = $this->makeStore([
            new Response(404, [], '{}'),
            new Response(200, [], '{"result": true}'),
        ]);

        $store->ensureCollection(1024);

        $this->assertCount(2, $this->history);
        $this->assertSame('GET', $this->history[0]['request']->getMethod());
        $this->assertSame('PUT', $this->history[1]['request']->getMethod());
        $this->assertStringContainsString(
            '/collections/' . self::COLLECTION,
            (string) $this->history[1]['request']->getUri()
        );
    }

    public function test_it_skips_collection_creation_when_it_already_exists(): void
    {
        $store = $this->makeStore([
            new Response(200, [], '{"result": {"status": "green"}}'),
        ]);

        $store->ensureCollection(1024);

        $this->assertCount(1, $this->history);
        $this->assertSame('GET', $this->history[0]['request']->getMethod());
    }

    public function test_it_upserts_a_point_with_correct_structure(): void
    {
        $store = $this->makeStore([
            new Response(200, [], '{"result": {"status": "ok"}}'),
        ]);

        $vector  = array_fill(0, 4, 0.5);
        $payload = ['document_name' => 'ZInfV-1', 'content' => 'test'];

        $store->upsert('test-uuid', $vector, $payload);

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];

        $this->assertSame('PUT', $request->getMethod());
        $this->assertStringContainsString(
            '/collections/' . self::COLLECTION . '/points',
            (string) $request->getUri()
        );

        $body = json_decode((string) $request->getBody(), true);
        $this->assertArrayHasKey('points', $body);
        $this->assertCount(1, $body['points']);

        $point = $body['points'][0];
        $this->assertSame('test-uuid', $point['id']);
        $this->assertSame($vector, $point['vector']);
        $this->assertSame($payload, $point['payload']);
    }

    // -------------------------------------------------------------------------

    /** @param  Response[]  $responses */
    private function makeStore(array $responses): QdrantStore
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new QdrantStore(
            new Client(['handler' => $stack]),
            self::BASE_URL,
            self::COLLECTION,
        );
    }
}
