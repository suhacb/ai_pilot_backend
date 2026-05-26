<?php

namespace App\Http\Controllers;

use App\Services\Agent\OllamaClient;
use Illuminate\Http\JsonResponse;

class ModelController extends Controller
{
    public function __construct(
        private readonly OllamaClient $ollama,
    ) {}

    /**
     * GET /api/models
     * List all models available in Ollama, annotated with their role.
     */
    public function index(): JsonResponse
    {
        $embeddingModel = config('services.ollama.embedding_model');

        $models = collect($this->ollama->listModels())
            ->map(fn(string $name) => [
                'name' => $name,
                'role' => $name === $embeddingModel ? 'embedding' : 'generative',
            ])
            ->values();

        return response()->json($models);
    }
}
