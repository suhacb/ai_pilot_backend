<?php

namespace App\Services\Ingestion;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
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
     * Delete all indexed chunks belonging to a given document.
     *
     * @throws \RuntimeException on HTTP error
     */
    public function deleteByDocument(string $documentName): void
    {
        try {
            $this->client->post("$this->url/api/$this->index/_delete_by_query", [
                'auth' => [$this->user, $this->password],
                'json' => [
                    'query' => [
                        'term' => ['document_name' => $documentName],
                    ],
                ],
            ]);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() !== 404) {
                throw new \RuntimeException(
                    "ZincSearch deleteByDocument failed: {$e->getMessage()}",
                    0,
                    $e
                );
            }
            // 404 → index does not exist yet, nothing to delete
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                "ZincSearch deleteByDocument failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

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
