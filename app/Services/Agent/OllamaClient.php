<?php

namespace App\Services\Agent;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class OllamaClient
{
    public function __construct(
        private readonly Client $client,
        private readonly string $url,
    ) {}

    /**
     * Generate a completion from Ollama.
     *
     * @param  bool  $jsonFormat  When true, constrains output to JSON format (for ReAct loop).
     *                            Set false for synthesis calls that return natural prose.
     *
     * @throws \RuntimeException on HTTP error
     */
    public function generate(string $prompt, string $model, bool $jsonFormat = true): string
    {
        $this->ensureOnlyModelLoaded($model);

        $payload = [
            'model'  => $model,
            'prompt' => $prompt,
            'stream' => false,
        ];

        if ($jsonFormat) {
            $payload['format'] = 'json';
        }

        try {
            $response = $this->client->post("$this->url/api/generate", [
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                "Ollama generate request failed: {$e->getMessage()}",
                0,
                $e
            );
        }

        $data = json_decode((string) $response->getBody(), true);

        return $data['response'];
    }

    /**
     * Check which model is currently loaded via /api/ps.
     * Returns the model name, or null if none is loaded or the check fails.
     */
    public function getLoadedModel(): ?string
    {
        try {
            $response = $this->client->get("$this->url/api/ps");
            $data     = json_decode((string) $response->getBody(), true);
            $models   = $data['models'] ?? [];
            return !empty($models) ? $models[0]['name'] : null;
        } catch (GuzzleException) {
            return null; // proceed without unloading if the check fails
        }
    }

    /**
     * Unload a model from memory by setting keep_alive to 0.
     * Best-effort: errors are silently ignored.
     */
    private function unloadModel(string $model): void
    {
        try {
            $this->client->post("$this->url/api/generate", [
                'json' => ['model' => $model, 'keep_alive' => 0],
            ]);
        } catch (GuzzleException) {
            // best effort — don't fail if unload request fails
        }
    }

    /**
     * Unload any currently loaded model that differs from $model
     * so that only one model occupies VRAM at a time.
     */
    private function ensureOnlyModelLoaded(string $model): void
    {
        $loaded = $this->getLoadedModel();

        if ($loaded !== null && $loaded !== $model) {
            $this->unloadModel($loaded);
        }
    }

    /**
     * List all models available in Ollama.
     *
     * @return string[]  model names
     *
     * @throws \RuntimeException on HTTP error
     */
    public function listModels(): array
    {
        try {
            $response = $this->client->get("$this->url/api/tags");
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                "Ollama tags request failed: {$e->getMessage()}",
                0,
                $e
            );
        }

        $data = json_decode((string) $response->getBody(), true);

        return array_column($data['models'] ?? [], 'name');
    }
}
