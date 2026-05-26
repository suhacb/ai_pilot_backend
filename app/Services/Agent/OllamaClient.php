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
