<?php

namespace Tests\Feature\Api;

use App\Services\Agent\OllamaClient;
use Tests\TestCase;

class ModelControllerTest extends TestCase
{
    // ── GET /api/models ──────────────────────────────────────────────────────

    public function test_it_returns_models_with_roles(): void
    {
        $this->bindOllamaClient(['gemma4:27b', 'gemma4:4b', 'mxbai-embed-large']);

        $this->getJson('/api/models')
             ->assertStatus(200)
             ->assertJsonCount(3)
             ->assertJsonStructure(['*' => ['name', 'role']]);
    }

    public function test_it_marks_the_configured_embedding_model_correctly(): void
    {
        $this->bindOllamaClient(['gemma4:27b', 'mxbai-embed-large']);

        $response = $this->getJson('/api/models')->assertStatus(200);

        $models = collect($response->json());

        $this->assertSame('embedding',  $models->firstWhere('name', 'mxbai-embed-large')['role']);
        $this->assertSame('generative', $models->firstWhere('name', 'gemma4:27b')['role']);
    }

    public function test_it_returns_empty_array_when_ollama_has_no_models(): void
    {
        $this->bindOllamaClient([]);

        $this->getJson('/api/models')
             ->assertStatus(200)
             ->assertExactJson([]);
    }

    // -------------------------------------------------------------------------

    private function bindOllamaClient(array $modelNames): void
    {
        $stub = $this->createMock(OllamaClient::class);
        $stub->method('listModels')->willReturn($modelNames);

        $this->app->instance(OllamaClient::class, $stub);
    }
}
