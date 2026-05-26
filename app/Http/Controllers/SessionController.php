<?php

namespace App\Http\Controllers;

use App\Models\AgentSession;
use App\Models\AgentStep;
use App\Services\Agent\AgentOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SessionController extends Controller
{
    public function __construct(
        private readonly AgentOrchestrator $orchestrator,
    ) {}

    /**
     * POST /api/sessions
     * Create a new agent session.
     */
    public function store(Request $request): JsonResponse
    {
        $model         = $request->input('model', config('agent.default_model'));
        $modelPlanning = $request->input('model_planning', config('agent.default_planning_model'));

        $session = AgentSession::create([
            'model_generative' => $model,
            'model_planning'   => $modelPlanning,
            'model_embedding'  => config('services.ollama.embedding_model'),
        ]);

        return response()->json([
            'session_id' => $session->id,
            'model'      => $session->model_generative,
        ], 201);
    }

    /**
     * POST /api/sessions/{id}/query
     * Run the agent on a prompt and stream the response as SSE.
     */
    public function query(Request $request, string $id): StreamedResponse
    {
        $request->validate(['prompt' => 'required|string']);

        $session = AgentSession::findOrFail($id);
        $prompt  = $request->input('prompt');

        $generator = $this->orchestrator->run($prompt, $session);

        return new StreamedResponse(function () use ($generator) {
            foreach ($generator as $event) {
                echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
            echo "data: [DONE]\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    /**
     * GET /api/sessions/{id}/steps
     * Return all recorded ReAct steps for a session.
     */
    public function steps(string $id): JsonResponse
    {
        $session = AgentSession::findOrFail($id);

        $steps = AgentStep::where('session_id', $session->id)
            ->orderBy('step_index')
            ->get();

        return response()->json($steps);
    }
}
