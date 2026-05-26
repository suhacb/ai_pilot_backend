<?php

namespace Tests\Feature\Agent;

use App\Models\AgentSession;
use App\Models\AgentStep;
use App\Services\Agent\AgentOrchestrator;
use App\Services\Agent\OllamaClient;
use App\Services\Agent\Tools\FulltextSearchTool;
use App\Services\Agent\Tools\GetDocumentTool;
use App\Services\Agent\Tools\SemanticSearchTool;
use App\Services\Agent\Tools\WebSearchTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private AgentSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = AgentSession::create([
            'model_generative' => 'test-generative',
            'model_planning'   => 'test-planning',
            'model_embedding'  => 'test-embed',
        ]);
    }

    public function test_it_yields_step_then_answer_events(): void
    {
        $orchestrator = $this->makeOrchestrator(ollamaResponses: [
            $this->toolCallResponse('search_semantic', ['query' => 'ZInfV-1 obveznosti']),
            $this->finishResponse('Obveznosti so naslednje...'),
            'Sintetiziran odgovor.',
        ], toolObservation: 'Našel sem relevantne rezultate.');

        $events = iterator_to_array($orchestrator->run('Kakšne so naše obveznosti?', $this->session), false);

        $this->assertCount(2, $events);
        $this->assertSame('step',   $events[0]['type']);
        $this->assertSame('answer', $events[1]['type']);
    }

    public function test_it_yields_correct_step_payload(): void
    {
        $orchestrator = $this->makeOrchestrator(ollamaResponses: [
            $this->toolCallResponse('search_fulltext', ['query' => 'člen 12', 'top_k' => 3]),
            $this->finishResponse('Odgovor.'),
            'Sintetiziran odgovor.',
        ], toolObservation: 'Rezultati iskanja.');

        $events = iterator_to_array($orchestrator->run('Iščem člen 12.', $this->session), false);

        $step = $events[0];
        $this->assertSame('search_fulltext', $step['action_tool']);
        $this->assertSame(['query' => 'člen 12', 'top_k' => 3], $step['action_params']);
        $this->assertSame('Rezultati iskanja.', $step['observation']);
        $this->assertNotEmpty($step['reasoning']);
    }

    public function test_it_yields_correct_answer_payload(): void
    {
        $orchestrator = $this->makeOrchestrator(ollamaResponses: [
            $this->finishResponse('intermediate'),
            'To je moj končni odgovor.',  // synthesis call returns the actual content
        ]);

        $events = iterator_to_array($orchestrator->run('Vprašanje.', $this->session), false);

        $this->assertCount(1, $events);
        $this->assertSame('answer', $events[0]['type']);
        $this->assertSame('To je moj končni odgovor.', $events[0]['content']);
    }

    public function test_it_dispatches_the_correct_tool(): void
    {
        $mockSemantic = $this->createMock(SemanticSearchTool::class);
        $mockSemantic->expects($this->once())->method('execute')->willReturn('semantic results');

        $mockFulltext = $this->createMock(FulltextSearchTool::class);
        $mockFulltext->expects($this->never())->method('execute');

        $orchestrator = $this->makeOrchestrator(
            ollamaResponses: [
                $this->toolCallResponse('search_semantic', ['query' => 'test']),
                $this->finishResponse('done'),
                'synthesis result',
            ],
            semanticTool: $mockSemantic,
            fulltextTool: $mockFulltext,
        );

        iterator_to_array($orchestrator->run('query', $this->session), false);
    }

    public function test_it_persists_agent_steps_to_the_database(): void
    {
        $orchestrator = $this->makeOrchestrator(ollamaResponses: [
            $this->toolCallResponse('search_web', ['query' => 'dobavitelji']),
            $this->finishResponse('Ocena tveganja.'),
            'Synthesized risk assessment.',
        ], toolObservation: 'Spletni rezultati.');

        iterator_to_array($orchestrator->run('Oceni dobavitelja.', $this->session), false);

        $this->assertDatabaseCount('agent_steps', 2);

        $toolStep = AgentStep::where('action_tool', 'search_web')->first();
        $this->assertNotNull($toolStep);
        $this->assertSame('Spletni rezultati.', $toolStep->observation);

        $finishStep = AgentStep::whereNull('action_tool')->first();
        $this->assertNotNull($finishStep);
    }

    public function test_it_stops_after_max_iterations_and_yields_partial_answer(): void
    {
        // Always return a tool call — never finish
        $infiniteToolCall = $this->toolCallResponse('search_semantic', ['query' => 'loop']);

        $orchestrator = $this->makeOrchestrator(
            ollamaResponses: array_fill(0, 3, $infiniteToolCall),
            toolObservation: 'result',
            maxIterations: 3,
        );

        $events = iterator_to_array($orchestrator->run('query', $this->session), false);

        $last = end($events);
        $this->assertSame('answer', $last['type']);
        $this->assertStringContainsString('iterations', strtolower($last['content']));
    }

    public function test_it_handles_malformed_llm_json_gracefully(): void
    {
        $mockOllama = $this->createMock(OllamaClient::class);
        $mockOllama->method('generate')->willReturn('This is not JSON at all!!!');

        $orchestrator = new AgentOrchestrator(
            $mockOllama,
            $this->createMock(SemanticSearchTool::class),
            $this->createMock(FulltextSearchTool::class),
            $this->createMock(WebSearchTool::class),
            $this->createMock(GetDocumentTool::class),
            maxIterations: 1,
        );

        $events = iterator_to_array($orchestrator->run('query', $this->session), false);

        $last = end($events);
        $this->assertSame('answer', $last['type']);
    }

    public function test_it_uses_planning_model_for_loop_and_generative_for_synthesis(): void
    {
        $session = AgentSession::create([
            'model_generative' => 'large-model',
            'model_planning'   => 'small-model',
            'model_embedding'  => 'test-embed',
        ]);

        $callLog    = [];
        $mockOllama = $this->createMock(OllamaClient::class);
        $mockOllama->method('generate')
            ->willReturnCallback(function (string $prompt, string $model, bool $jsonFormat = true) use (&$callLog): string {
                $callLog[] = ['model' => $model, 'jsonFormat' => $jsonFormat];

                if ($model === 'small-model') {
                    return json_encode([
                        'reasoning'  => 'Zbral sem dovolj informacij.',
                        'action'     => 'finish',
                        'parameters' => ['final_answer' => 'intermediate'],
                    ]);
                }

                return 'Sintetiziran končni odgovor.';
            });

        $orchestrator = new AgentOrchestrator(
            $mockOllama,
            $this->stubTool(SemanticSearchTool::class, ''),
            $this->stubTool(FulltextSearchTool::class, ''),
            $this->stubTool(WebSearchTool::class, ''),
            $this->stubTool(GetDocumentTool::class, ''),
            maxIterations: 8,
        );

        $events = iterator_to_array($orchestrator->run('Vprašanje.', $session), false);

        $this->assertCount(2, $callLog);
        $this->assertSame('small-model', $callLog[0]['model']);
        $this->assertTrue($callLog[0]['jsonFormat']);
        $this->assertSame('large-model', $callLog[1]['model']);
        $this->assertFalse($callLog[1]['jsonFormat']);

        $answer = end($events);
        $this->assertSame('answer', $answer['type']);
        $this->assertSame('Sintetiziran končni odgovor.', $answer['content']);
    }

    // -------------------------------------------------------------------------

    private function toolCallResponse(string $tool, array $params): string
    {
        return json_encode([
            'reasoning'  => "I should use $tool to find relevant information.",
            'action'     => $tool,
            'parameters' => $params,
        ]);
    }

    private function finishResponse(string $answer): string
    {
        return json_encode([
            'reasoning'  => 'I have gathered sufficient information.',
            'action'     => 'finish',
            'parameters' => ['final_answer' => $answer],
        ]);
    }

    /**
     * @param  string[]  $ollamaResponses  Pre-encoded JSON strings the mock LLM will return in order.
     */
    private function makeOrchestrator(
        array $ollamaResponses,
        string $toolObservation = 'Tool result.',
        ?SemanticSearchTool $semanticTool = null,
        ?FulltextSearchTool $fulltextTool = null,
        int $maxIterations = 8,
    ): AgentOrchestrator {
        $mockOllama = $this->createMock(OllamaClient::class);
        $mockOllama->method('generate')->willReturnOnConsecutiveCalls(...$ollamaResponses);

        $semantic = $semanticTool ?? $this->stubTool(SemanticSearchTool::class, $toolObservation);
        $fulltext  = $fulltextTool  ?? $this->stubTool(FulltextSearchTool::class, $toolObservation);
        $web      = $this->stubTool(WebSearchTool::class, $toolObservation);
        $getDoc   = $this->stubTool(GetDocumentTool::class, $toolObservation);

        return new AgentOrchestrator($mockOllama, $semantic, $fulltext, $web, $getDoc, $maxIterations);
    }

    private function stubTool(string $class, string $observation): object
    {
        $mock = $this->createMock($class);
        $mock->method('execute')->willReturn($observation);
        return $mock;
    }
}
