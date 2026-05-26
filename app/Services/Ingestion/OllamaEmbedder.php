<?php

namespace App\Services\Ingestion;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class OllamaEmbedder
{
    public function __construct(
        private readonly Client $client,
        private readonly string $url,
        private readonly string $model,
    ) {}

    /**
     * Embed a text string and return the vector as a flat float array.
     *
     * @return float[]
     *
     * @throws \RuntimeException on HTTP error
     */
    public function embed(string $text): array
    {
        try {
            $response = $this->client->post("$this->url/api/embed", [
                'json' => [
                    'model' => $this->model,
                    'input' => $text,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Ollama embedding request failed: {$e->getMessage()}", 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);

        return $data['embeddings'][0];
    }
}
