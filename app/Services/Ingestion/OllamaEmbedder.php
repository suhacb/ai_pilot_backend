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
    // mxbai-embed-large context limit is 512 tokens; Slovenian legal text tokenizes at ~2 chars/token with BERT WordPiece
    private const MAX_CHARS = 900;

    public function embed(string $text): array
    {
        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS);
        }

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
