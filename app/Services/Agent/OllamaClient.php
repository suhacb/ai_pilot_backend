<?php

namespace App\Services\Agent;

use App\Exceptions\GenerationCancelledException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\StreamInterface;

class OllamaClient
{
    public function __construct(
        private readonly Client $client,
        private readonly string $url,
    ) {}

    /** Per-request in-memory cache to avoid repeated DB lookups per generate() call. */
    private array $ctxCache = [];

    /** Callable that returns true when the current generation should be aborted. */
    private mixed $cancelCheck = null;

    public function setCancelCheck(?callable $fn): void
    {
        $this->cancelCheck = $fn;
    }

    /**
     * Look up the registered context window for a model.
     * Falls back to 8 192 if the model is not in the registry.
     */
    public function contextWindowFor(string $model): int
    {
        if (!array_key_exists($model, $this->ctxCache)) {
            $this->ctxCache[$model] = \App\Models\OllamaModel::where('name', $model)->value('context_window') ?? 8192;
        }
        return $this->ctxCache[$model];
    }

    /**
     * Generate a completion from Ollama using a streaming response so that
     * a registered cancel check can abort mid-generation.
     *
     * @param  bool  $jsonFormat  When true, constrains output to JSON format (for ReAct loop).
     *                            Set false for synthesis calls that return natural prose.
     *
     * @throws \RuntimeException               on HTTP error
     * @throws GenerationCancelledException    when the cancel check returns true mid-stream
     */
    public function generate(string $prompt, string $model, bool $jsonFormat = true): string
    {
        $this->ensureOnlyModelLoaded($model);

        $payload = [
            'model'   => $model,
            'prompt'  => $prompt,
            'stream'  => true,
            'options' => ['num_ctx' => $this->contextWindowFor($model)],
        ];

        if ($jsonFormat) {
            $payload['format'] = 'json';
        }

        try {
            $response = $this->client->post("$this->url/api/generate", [
                'json'   => $payload,
                'stream' => true,
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                "Ollama generate request failed: {$e->getMessage()}",
                0,
                $e
            );
        }

        $body       = $response->getBody();
        $result     = '';
        $tokenCount = 0;

        while (!$body->eof()) {
            $line = $this->readStreamLine($body);
            if ($line === '') {
                continue;
            }

            $data = json_decode($line, true);
            if (!is_array($data)) {
                continue;
            }

            $result .= $data['response'] ?? '';

            if ($data['done'] ?? false) {
                break;
            }

            // Check cancellation every 20 tokens to keep cache lookups cheap.
            $tokenCount++;
            if ($tokenCount % 20 === 0 && $this->cancelCheck && ($this->cancelCheck)()) {
                $body->close();
                throw new GenerationCancelledException();
            }
        }

        return $result;
    }

    private function readStreamLine(StreamInterface $body): string
    {
        $line = '';
        while (!$body->eof()) {
            $char = $body->read(1);
            if ($char === "\n") {
                break;
            }
            $line .= $char;
        }
        return trim($line);
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
