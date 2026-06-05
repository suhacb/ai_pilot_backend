<?php

namespace Tests\Feature\Api;

use App\Models\AgentSession;
use App\Models\AgentStep;
use App\Services\Agent\AgentOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── POST /api/sessions ───────────────────────────────────────────────────

    public function test_it_creates_a_session_and_returns_session_id(): void
    {
        $this->createOllamaModel('gemma4:26b', 'generative');

        $response = $this->postJson('/api/sessions', ['model' => 'gemma4:26b']);

        $response->assertStatus(201)
                 ->assertJsonStructure(['session_id', 'model']);

        $this->assertDatabaseCount('agent_sessions', 1);
        $this->assertSame('gemma4:26b', AgentSession::first()->model_generative);
    }

    public function test_it_stores_model_planning_when_provided(): void
    {
        $this->createOllamaModel('qwen3:14b', 'generative');

        $this->postJson('/api/sessions', [
            'model'          => 'qwen3:14b',
            'model_planning' => 'qwen3:14b',
        ])->assertStatus(201);

        $this->assertSame('qwen3:14b', AgentSession::first()->model_planning);
    }

    public function test_it_uses_default_model_when_not_provided(): void
    {
        $response = $this->postJson('/api/sessions', []);

        $response->assertStatus(201);
        $this->assertNotEmpty(AgentSession::first()->model_generative);
    }

    // ── POST /api/sessions/{id}/query ────────────────────────────────────────

    public function test_it_returns_sse_content_type_for_query(): void
    {
        $session = $this->createSession();
        $this->bindOrchestratorStub($session, [
            ['type' => 'answer', 'content' => 'Odgovor.'],
        ]);

        $response = $this->post("/api/sessions/{$session->id}/query", ['prompt' => 'test']);

        $response->assertStatus(200);
        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));
    }

    public function test_it_streams_step_and_answer_events(): void
    {
        $session = $this->createSession();
        $this->bindOrchestratorStub($session, [
            ['type' => 'step',   'reasoning' => 'Iščem.', 'action_tool' => 'search_semantic', 'action_params' => [], 'observation' => 'Rezultati.'],
            ['type' => 'answer', 'content'   => 'Končni odgovor.'],
        ]);

        $content = $this->streamedContent("/api/sessions/{$session->id}/query", ['prompt' => 'test']);

        $this->assertStringContainsString('"type":"step"', $content);
        $this->assertStringContainsString('"type":"answer"', $content);
        $this->assertStringContainsString('[DONE]', $content);
    }

    public function test_it_returns_422_when_prompt_is_missing(): void
    {
        $session = $this->createSession();

        $this->postJson("/api/sessions/{$session->id}/query", [])
             ->assertStatus(422);
    }

    public function test_it_returns_404_for_unknown_session_on_query(): void
    {
        $this->post('/api/sessions/nonexistent-id/query', ['prompt' => 'test'])
             ->assertStatus(404);
    }

    // ── GET /api/sessions/{id}/steps ─────────────────────────────────────────

    public function test_it_returns_steps_for_a_session(): void
    {
        $session = $this->createSession();

        AgentStep::create([
            'session_id'  => $session->id,
            'step_index'  => 0,
            'reasoning'   => 'Razmišljam.',
            'action_tool' => 'search_semantic',
            'action_params' => ['query' => 'test'],
            'observation' => 'Rezultati.',
        ]);

        $this->getJson("/api/sessions/{$session->id}/steps")
             ->assertStatus(200)
             ->assertJsonCount(1)
             ->assertJsonPath('0.action_tool', 'search_semantic');
    }

    public function test_it_returns_empty_array_when_no_steps(): void
    {
        $session = $this->createSession();

        $this->getJson("/api/sessions/{$session->id}/steps")
             ->assertStatus(200)
             ->assertExactJson([]);
    }

    public function test_it_returns_404_for_unknown_session_on_steps(): void
    {
        $this->getJson('/api/sessions/nonexistent-id/steps')
             ->assertStatus(404);
    }

    // -------------------------------------------------------------------------

    private function createSession(string $model = 'gemma4:26b'): AgentSession
    {
        return AgentSession::create([
            'model_generative' => $model,
            'model_planning'   => 'qwen3:14b',
            'model_embedding'  => 'mxbai-embed-large',
        ]);
    }

    /**
     * Bind a stub AgentOrchestrator whose run() generator yields the given events.
     */
    private function bindOrchestratorStub(AgentSession $session, array $events): void
    {
        $stub = $this->createMock(AgentOrchestrator::class);
        $stub->method('run')->willReturnCallback(function () use ($events) {
            yield from $events;
        });

        $this->app->instance(AgentOrchestrator::class, $stub);
    }

    /**
     * Execute a POST request and capture the streamed response body.
     *
     * ob_flush() inside the SSE callback pushes content to the parent buffer,
     * so we pass an accumulator callback to ob_start() to intercept each flush.
     */
    private function streamedContent(string $uri, array $data): string
    {
        $response = $this->post($uri, $data);

        $captured = '';
        ob_start(function (string $chunk) use (&$captured): string {
            $captured .= $chunk;
            return '';
        });
        $response->baseResponse->sendContent();
        ob_end_clean();

        return $captured;
    }
}
