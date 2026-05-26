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
     * Returns the raw response string from the model (expected to be JSON
     * when format=json is set, which is enforced here).
     *
     * @throws \RuntimeException on HTTP error
     */
    public function generate(string $prompt, string $model): string
    {
        try {
            $response = $this->client->post("$this->url/api/generate", [
                'json' => [
                    'model'  => $model,
                    'prompt' => $prompt,
                    'stream' => false,
                    'format' => 'json',
                ],
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
}
