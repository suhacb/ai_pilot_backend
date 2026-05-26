<?php

namespace App\Services\Ingestion;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ZincSearchStore
{
    public function __construct(
        private readonly Client $client,
        private readonly string $url,
        private readonly string $index,
        private readonly string $user,
        private readonly string $password,
    ) {}

    /**
     * Upsert a document into ZincSearch.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws \RuntimeException on HTTP error
     */
    public function upsert(string $docId, array $fields): void
    {
        try {
            $this->client->put("$this->url/api/$this->index/_doc/$docId", [
                'auth' => [$this->user, $this->password],
                'json' => $fields,
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                "ZincSearch upsert failed for doc $docId: {$e->getMessage()}",
                0,
                $e
            );
        }
    }
}
