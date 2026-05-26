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
    private const MODEL    = 'gemma4:26b';

    private array $history = [];

    public function test_it_posts_to_the_generate_endpoint_with_correct_payload(): void
    {
        $client = $this->makeClient([
            $this->psResponse(),
            new Response(200, [], json_encode([
                'model'    => self::MODEL,
                'response' => '{"action":"finish"}',
                'done'     => true,
            ])),
        ]);

        $client->generate('My prompt', self::MODEL);

        // history[0] = GET /api/ps, history[1] = POST /api/generate
        $this->assertCount(2, $this->history);
        $request = $this->history[1]['request'];

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
            $this->psResponse(),
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
            $this->psResponse(),
            new Response(500, [], 'Internal Server Error'),
        ]);

        $this->expectException(\RuntimeException::class);

        $client->generate('prompt', self::MODEL);
    }

    public function test_it_omits_format_field_when_json_format_is_false(): void
    {
        $client = $this->makeClient([
            $this->psResponse(),
            new Response(200, [], json_encode([
                'model'    => self::MODEL,
                'response' => 'Synthesized prose answer.',
                'done'     => true,
            ])),
        ]);

        $client->generate('My prompt', self::MODEL, false);

        $body = json_decode((string) $this->history[1]['request']->getBody(), true);
        $this->assertArrayNotHasKey('format', $body);
    }

    public function test_it_unloads_different_model_before_generating(): void
    {
        $client = $this->makeClient([
            $this->psResponse('gemma4:e4b'),      // ps: different model loaded
            $this->generateResponse(''),           // unload keep_alive=0
            new Response(200, [], json_encode([
                'model'    => self::MODEL,
                'response' => '{"action":"finish"}',
                'done'     => true,
            ])),
        ]);

        $client->generate('prompt', self::MODEL);

        // 3 requests: ps, unload, generate
        $this->assertCount(3, $this->history);

        $unloadRequest = $this->history[1]['request'];
        $unloadBody    = json_decode((string) $unloadRequest->getBody(), true);
        $this->assertSame('gemma4:e4b', $unloadBody['model']);
        $this->assertSame(0, $unloadBody['keep_alive']);

        $generateRequest = $this->history[2]['request'];
        $generateBody    = json_decode((string) $generateRequest->getBody(), true);
        $this->assertSame(self::MODEL, $generateBody['model']);
        $this->assertSame('prompt', $generateBody['prompt']);
    }

    public function test_it_does_not_unload_when_same_model_is_already_loaded(): void
    {
        $client = $this->makeClient([
            $this->psResponse(self::MODEL),
            new Response(200, [], json_encode([
                'model'    => self::MODEL,
                'response' => '{"action":"finish"}',
                'done'     => true,
            ])),
        ]);

        $client->generate('prompt', self::MODEL);

        // Only 2 requests: ps + generate (no unload)
        $this->assertCount(2, $this->history);
        $this->assertSame('GET',  $this->history[0]['request']->getMethod());
        $this->assertSame('POST', $this->history[1]['request']->getMethod());
    }

    public function test_it_skips_unload_when_nothing_is_loaded(): void
    {
        $client = $this->makeClient([
            $this->psResponse(),   // nothing loaded
            new Response(200, [], json_encode([
                'model'    => self::MODEL,
                'response' => '{"action":"finish"}',
                'done'     => true,
            ])),
        ]);

        $client->generate('prompt', self::MODEL);

        $this->assertCount(2, $this->history);
    }

    public function test_it_proceeds_when_ps_check_fails(): void
    {
        $client = $this->makeClient([
            new Response(500, [], 'error'),   // ps fails
            new Response(200, [], json_encode([
                'model'    => self::MODEL,
                'response' => '{"action":"finish"}',
                'done'     => true,
            ])),
        ]);

        // Should not throw — ps failure is silently ignored
        $result = $client->generate('prompt', self::MODEL);
        $this->assertSame('{"action":"finish"}', $result);
    }

    public function test_list_models_returns_model_names_from_ollama_tags(): void
    {
        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'models' => [
                    ['name' => 'gemma4:26b'],
                    ['name' => 'mxbai-embed-large'],
                ],
            ])),
        ]);

        $models = $client->listModels();

        $this->assertSame(['gemma4:26b', 'mxbai-embed-large'], $models);
    }

    public function test_list_models_sends_get_to_api_tags(): void
    {
        $client = $this->makeClient([
            new Response(200, [], json_encode(['models' => []])),
        ]);

        $client->listModels();

        $request = $this->history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(self::BASE_URL . '/api/tags', (string) $request->getUri());
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

    private function psResponse(?string $loadedModel = null): Response
    {
        $models = $loadedModel ? [['name' => $loadedModel]] : [];
        return new Response(200, [], json_encode(['models' => $models]));
    }

    private function generateResponse(string $responseText): Response
    {
        return new Response(200, [], json_encode([
            'model'    => self::MODEL,
            'response' => $responseText,
            'done'     => true,
        ]));
    }
}
