<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── GET /api/models ──────────────────────────────────────────────────────

    public function test_it_returns_registered_models(): void
    {
        $this->createOllamaModel('gemma4:26b',        'generative');
        $this->createOllamaModel('gemma4:e4b',        'generative');
        $this->createOllamaModel('mxbai-embed-large', 'embedding');

        $this->getJson('/api/models')
             ->assertStatus(200)
             ->assertJsonCount(3)
             ->assertJsonStructure(['*' => ['id', 'name', 'display_name', 'role', 'context_window', 'is_active']]);
    }

    public function test_it_returns_correct_roles(): void
    {
        $this->createOllamaModel('gemma4:26b',        'generative');
        $this->createOllamaModel('mxbai-embed-large', 'embedding');

        $response = $this->getJson('/api/models')->assertStatus(200);
        $models   = collect($response->json());

        $this->assertSame('embedding',  $models->firstWhere('name', 'mxbai-embed-large')['role']);
        $this->assertSame('generative', $models->firstWhere('name', 'gemma4:26b')['role']);
    }

    public function test_it_returns_empty_array_when_no_models_registered(): void
    {
        $this->getJson('/api/models')
             ->assertStatus(200)
             ->assertExactJson([]);
    }

    public function test_soft_deleted_models_are_excluded(): void
    {
        $model = $this->createOllamaModel('gemma4:26b', 'generative');
        $model->delete();

        $this->getJson('/api/models')
             ->assertStatus(200)
             ->assertExactJson([]);
    }

    // ── DELETE /api/models/{id} ──────────────────────────────────────────────

    public function test_it_soft_deletes_a_model(): void
    {
        $model = $this->createOllamaModel('gemma4:26b', 'generative');

        $this->deleteJson("/api/models/{$model->id}")
             ->assertStatus(200)
             ->assertExactJson(['deleted' => true]);

        $this->assertSoftDeleted('ollama_models', ['id' => $model->id]);
    }

    public function test_it_returns_404_for_unknown_model(): void
    {
        $this->deleteJson('/api/models/99999')->assertStatus(404);
    }
}
