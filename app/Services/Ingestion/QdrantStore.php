<?php

namespace App\Services\Ingestion;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;

class QdrantStore
{
    public function __construct(
        private readonly Client $client,
        private readonly string $url,
        private readonly string $collection,
    ) {}

    /**
     * Create the Qdrant collection if it does not already exist.
     *
     * @throws \RuntimeException on unexpected HTTP error
     */
    public function ensureCollection(int $vectorSize): void
    {
        try {
            $this->client->get("$this->url/collections/$this->collection");
            // 200 → collection exists, nothing to do
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                $this->createCollection($vectorSize);
            } else {
                throw new \RuntimeException(
                    "Qdrant error checking collection: {$e->getMessage()}",
                    0,
                    $e
                );
            }
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                "Qdrant connection error: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Upsert a single vector point into Qdrant.
     *
     * @param  float[]  $vector
     * @param  array<string, mixed>  $payload
     *
     * @throws \RuntimeException on HTTP error
     */
    public function upsert(string $pointId, array $vector, array $payload): void
    {
        try {
            $this->client->put("$this->url/collections/$this->collection/points", [
                'json' => [
                    'points' => [
                        [
                            'id'      => $pointId,
                            'vector'  => $vector,
                            'payload' => $payload,
                        ],
                    ],
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                "Qdrant upsert failed for point $pointId: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    private function createCollection(int $vectorSize): void
    {
        try {
            $this->client->put("$this->url/collections/$this->collection", [
                'json' => [
                    'vectors' => [
                        'size'     => $vectorSize,
                        'distance' => 'Cosine',
                    ],
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                "Qdrant collection creation failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }
}
