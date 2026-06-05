<?php

namespace App\Http\Controllers;

use App\Models\AgentSession;
use App\Models\AgentStep;
use App\Models\OllamaModel;
use App\Services\Agent\AgentOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SessionController extends Controller
{
    public function __construct(
        private readonly AgentOrchestrator $orchestrator,
    ) {}

    /**
     * GET /api/sessions
     * List all non-deleted sessions, newest first.
     */
    public function index(): JsonResponse
    {
        $sessions = AgentSession::orderByDesc('created_at')
            ->get(['id', 'model_generative', 'model_planning', 'model_locked', 'title', 'created_at']);

        return response()->json($sessions);
    }

    /**
     * POST /api/sessions
     * Create a new agent session.
     */
    public function store(Request $request): JsonResponse
    {
        $generativeNames = OllamaModel::where('role', 'generative')->where('is_active', true)->pluck('name');

        $validated = $request->validate([
            'model'          => ['sometimes', 'string', Rule::in($generativeNames)],
            'model_planning' => ['sometimes', 'string', Rule::in($generativeNames)],
        ]);

        $model         = $validated['model']          ?? config('agent.default_model');
        $modelPlanning = $validated['model_planning'] ?? config('agent.default_planning_model');

        $session = AgentSession::create([
            'model_generative' => $model,
            'model_planning'   => $modelPlanning,
            'model_embedding'  => config('services.ollama.embedding_model'),
        ]);

        return response()->json([
            'session_id'     => $session->id,
            'model'          => $session->model_generative,
            'model_planning' => $session->model_planning,
            'model_locked'   => false,
        ], 201);
    }

    /**
     * GET /api/sessions/{id}
     * Return current session state (model, lock status).
     */
    public function show(string $id): JsonResponse
    {
        $session = AgentSession::findOrFail($id);

        return response()->json([
            'session_id'     => $session->id,
            'model'          => $session->model_generative,
            'model_planning' => $session->model_planning,
            'model_locked'   => $session->model_locked,
            'title'          => $session->title,
        ]);
    }

    /**
     * POST /api/sessions/{id}/query
     * Run the agent on a prompt and stream the response as SSE.
     *
     * On the first query the model may be overridden via the request body.
     * After that the session is locked and model fields in the request are ignored.
     */
    public function query(Request $request, string $id): StreamedResponse
    {
        $generativeNames = OllamaModel::where('role', 'generative')->where('is_active', true)->pluck('name');

        $validated = $request->validate([
            'prompt'         => 'required|string',
            'model'          => ['sometimes', 'string', Rule::in($generativeNames)],
            'model_planning' => ['sometimes', 'string', Rule::in($generativeNames)],
        ]);

        $session = AgentSession::findOrFail($id);

        $title = null;

        if (!$session->model_locked) {
            $planningModel = $validated['model_planning'] ?? $session->model_planning ?? $validated['model'] ?? $session->model_generative;
            $title = $this->orchestrator->generateTitle($validated['prompt'], $planningModel);

            $session->update([
                'model_generative' => $validated['model']          ?? $session->model_generative,
                'model_planning'   => $validated['model_planning'] ?? $session->model_planning,
                'model_locked'     => true,
                'title'            => $title,
            ]);
        }

        $generator = $this->orchestrator->run($validated['prompt'], $session);

        return new StreamedResponse(function () use ($generator, $session, $title) {
            set_time_limit(0);

            $emit = function (array $event) {
                echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $emit([
                'type'           => 'model_locked',
                'model'          => $session->model_generative,
                'model_planning' => $session->model_planning,
            ]);

            if ($title !== null) {
                $emit(['type' => 'session_title', 'title' => $title]);
            }

            try {
                foreach ($generator as $event) {
                    $emit($event);
                }
            } catch (\Throwable $e) {
                Log::error('[SSE] Exception escaped generator', [
                    'session' => $session->id,
                    'error'   => $e->getMessage(),
                    'class'   => get_class($e),
                ]);
                $emit(['type' => 'error', 'message' => 'Prišlo je do nepričakovane napake.']);
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
     * DELETE /api/sessions/{id}
     * Soft-delete a session. The record and its steps remain in the database for audit purposes.
     */
    public function destroy(string $id): JsonResponse
    {
        $session = AgentSession::findOrFail($id);
        $session->delete();
        return response()->json(['deleted' => true]);
    }

    /**
     * POST /api/sessions/{id}/cancel
     * Signal the running agent to stop generation.
     * The flag is consumed and deleted by AgentOrchestrator once it handles the cancellation.
     */
    public function cancel(string $id): JsonResponse
    {
        $session = AgentSession::findOrFail($id);
        Cache::put("agent:cancelled:{$session->id}", true, now()->addMinutes(5));
        return response()->json(['cancelled' => true]);
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
