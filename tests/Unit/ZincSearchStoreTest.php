<?php

namespace Tests\Unit;

use App\Services\Ingestion\ZincSearchStore;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ZincSearchStoreTest extends TestCase
{
    private const BASE_URL  = 'http://zincsearch:4080';
    private const INDEX     = 'compliance_docs';
    private const USER      = 'admin';
    private const PASSWORD  = 'secret';

    private array $history = [];

    public function test_it_sends_a_put_request_to_the_correct_index_and_doc_id(): void
    {
        $store = $this->makeStore([
            new Response(200, [], '{"id": "test-id"}'),
        ]);

        $store->upsert('my-doc-id', ['content' => 'test']);

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];

        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame(
            self::BASE_URL . '/api/' . self::INDEX . '/_doc/my-doc-id',
            (string) $request->getUri()
        );
    }

    public function test_it_sends_basic_auth_credentials(): void
    {
        $store = $this->makeStore([
            new Response(200, [], '{"id": "test-id"}'),
        ]);

        $store->upsert('doc-id', ['content' => 'test']);

        $authHeader = $this->history[0]['request']->getHeader('Authorization');
        $this->assertNotEmpty($authHeader);

        $expected = 'Basic ' . base64_encode(self::USER . ':' . self::PASSWORD);
        $this->assertSame($expected, $authHeader[0]);
    }

    public function test_it_includes_all_required_fields_in_the_request_body(): void
    {
        $store = $this->makeStore([
            new Response(200, [], '{"id": "test-id"}'),
        ]);

        $fields = [
            'document_name' => 'ZInfV-1',
            'source_type'   => 'legislation',
            'section_title' => 'Člen 12',
            'chunk_index'   => 3,
            'content'       => 'Besedilo člena.',
            'content_hash'  => hash('sha256', 'Besedilo člena.'),
        ];

        $store->upsert('doc-id', $fields);

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);

        foreach ($fields as $key => $value) {
            $this->assertArrayHasKey($key, $body);
            $this->assertSame($value, $body[$key]);
        }
    }

    // -------------------------------------------------------------------------

    /** @param  Response[]  $responses */
    private function makeStore(array $responses): ZincSearchStore
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new ZincSearchStore(
            new Client(['handler' => $stack]),
            self::BASE_URL,
            self::INDEX,
            self::USER,
            self::PASSWORD,
        );
    }
}
