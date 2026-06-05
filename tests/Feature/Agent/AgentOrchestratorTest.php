<?php

namespace Tests\Feature\Agent;

use App\Models\AgentSession;
use App\Models\AgentStep;
use App\Services\Agent\AgentOrchestrator;
use App\Services\Agent\OllamaClient;
use App\Services\Agent\RagRetriever;
use App\Services\Agent\Tools\GetDocumentTool;
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
            $this->highRelevanceResponse(),
            $this->toolCallResponse('search_semantic', ['query' => 'ZInfV-1 obveznosti']),
            $this->finishResponse('Obveznosti so naslednje...'),
            'Sintetiziran odgovor.',
        ], toolObservation: 'Našel sem relevantne rezultate.');

        $events = iterator_to_array($orchestrator->run('Kakšne so naše obveznosti?', $this->session), false);

        $types = array_column($events, 'type');
        $this->assertContains('step',   $types);
        $this->assertContains('answer', $types);
        $this->assertSame('answer', end($events)['type']);
    }

    public function test_it_yields_correct_step_message(): void
    {
        $orchestrator = $this->makeOrchestrator(ollamaResponses: [
            $this->highRelevanceResponse(),
            $this->toolCallResponse('search_fulltext', ['query' => 'člen 12', 'top_k' => 3]),
            $this->finishResponse('Odgovor.'),
            'Sintetiziran odgovor.',
        ], toolObservation: 'Rezultati iskanja.');

        $events = iterator_to_array($orchestrator->run('Iščem člen 12.', $this->session), false);

        $stepMessages = array_column(
            array_filter($events, fn($e) => $e['type'] === 'step'),
            'message'
        );
        $this->assertNotEmpty(array_filter($stepMessages, fn($m) => str_contains($m ?? '', 'člen 12')));
    }

    public function test_it_yields_correct_answer_content(): void
    {
        $orchestrator = $this->makeOrchestrator(ollamaResponses: [
            $this->highRelevanceResponse(),
            $this->finishResponse('intermediate'),
            'To je moj končni odgovor.',
        ]);

        $events = iterator_to_array($orchestrator->run('Vprašanje.', $this->session), false);

        $answer = end($events);
        $this->assertSame('answer', $answer['type']);
        $this->assertSame('To je moj končni odgovor.', $answer['content']);
    }

    public function test_it_dispatches_semantic_search_through_retriever(): void
    {
        $mockRetriever = $this->createMock(RagRetriever::class);
        $mockRetriever->expects($this->once())->method('semanticSearch')->willReturn('semantic results');
        $mockRetriever->expects($this->never())->method('fulltextSearch');

        $orchestrator = $this->makeOrchestrator(
            ollamaResponses: [
                $this->highRelevanceResponse(),
                $this->toolCallResponse('search_semantic', ['query' => 'test']),
                $this->finishResponse('done'),
                'synthesis result',
            ],
            retriever: $mockRetriever,
        );

        iterator_to_array($orchestrator->run('query', $this->session), false);
    }

    public function test_it_persists_agent_steps_to_the_database(): void
    {
        $orchestrator = $this->makeOrchestrator(ollamaResponses: [
            $this->highRelevanceResponse(),
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

    public function test_it_stops_after_max_iterations_and_yields_answer(): void
    {
        $infiniteToolCall = $this->toolCallResponse('search_semantic', ['query' => 'loop']);

        $orchestrator = $this->makeOrchestrator(
            ollamaResponses: [
                $this->highRelevanceResponse(),
                $infiniteToolCall,
                $infiniteToolCall,
                $infiniteToolCall,
                'Odgovor na podlagi zbranih informacij.',
            ],
            toolObservation: 'result',
            maxIterations: 3,
        );

        $events = iterator_to_array($orchestrator->run('query', $this->session), false);

        $last = end($events);
        $this->assertSame('answer', $last['type']);
        $this->assertNotEmpty($last['content']);
    }

    public function test_it_handles_malformed_llm_json_gracefully(): void
    {
        $mockOllama = $this->createMock(OllamaClient::class);
        $mockOllama->method('generate')->willReturn('This is not JSON at all!!!');
        $mockOllama->method('contextWindowFor')->willReturn(8192);

        $orchestrator = new AgentOrchestrator(
            $mockOllama,
            $this->createMock(RagRetriever::class),
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
        $mockOllama->method('contextWindowFor')->willReturn(8192);
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
            $this->createMock(RagRetriever::class),
            $this->createMock(WebSearchTool::class),
            $this->createMock(GetDocumentTool::class),
            maxIterations: 8,
        );

        $events = iterator_to_array($orchestrator->run('Vprašanje.', $session), false);

        $planningCalls  = array_values(array_filter($callLog, fn($c) => $c['jsonFormat'] === true));
        $synthesisCalls = array_values(array_filter($callLog, fn($c) => $c['jsonFormat'] === false));

        $this->assertNotEmpty($planningCalls);
        $this->assertCount(1, $synthesisCalls);
        $this->assertSame('large-model', $synthesisCalls[0]['model']);
        foreach ($planningCalls as $call) {
            $this->assertSame('small-model', $call['model']);
        }

        $answer = end($events);
        $this->assertSame('answer', $answer['type']);
        $this->assertSame('Sintetiziran končni odgovor.', $answer['content']);
    }

    // -------------------------------------------------------------------------

    private function highRelevanceResponse(): string
    {
        return json_encode(['level' => 'HIGH', 'message' => null]);
    }

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

    private function makeOrchestrator(
        array $ollamaResponses,
        string $toolObservation = 'Tool result.',
        ?RagRetriever $retriever = null,
        int $maxIterations = 8,
    ): AgentOrchestrator {
        $mockOllama = $this->createMock(OllamaClient::class);
        $mockOllama->method('generate')->willReturnOnConsecutiveCalls(...$ollamaResponses);
        $mockOllama->method('contextWindowFor')->willReturn(8192);

        if ($retriever === null) {
            $retriever = $this->createMock(RagRetriever::class);
            $retriever->method('semanticSearch')->willReturn($toolObservation);
            $retriever->method('fulltextSearch')->willReturn($toolObservation);
        }

        $web    = $this->createMock(WebSearchTool::class);
        $web->method('execute')->willReturn($toolObservation);

        $getDoc = $this->createMock(GetDocumentTool::class);
        $getDoc->method('execute')->willReturn($toolObservation);

        return new AgentOrchestrator($mockOllama, $retriever, $web, $getDoc, $maxIterations);
    }
}
