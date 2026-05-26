<?php

namespace App\Services\Agent\Tools;

use App\Services\Ingestion\OllamaEmbedder;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class SemanticSearchTool
{
    public function __construct(
        private readonly OllamaEmbedder $embedder,
        private readonly Client $client,
        private readonly string $url,
        private readonly string $collection,
    ) {}

    /**
     * Search the vector store using semantic similarity.
     *
     * Params:
     *   query               string   — required
     *   top_k               int      — optional, default 5
     *   filter_source_type  string   — optional: internal_policy | legislation
     */
    public function execute(array $params): string
    {
        $query      = $params['query'];
        $topK       = (int) ($params['top_k'] ?? 5);
        $sourceType = $params['filter_source_type'] ?? null;

        $vector = $this->embedder->embed($query);

        $body = [
            'vector'       => $vector,
            'limit'        => $topK,
            'with_payload' => true,
        ];

        if ($sourceType !== null) {
            $body['filter'] = [
                'must' => [
                    ['key' => 'source_type', 'match' => ['value' => $sourceType]],
                ],
            ];
        }

        try {
            $response = $this->client->post(
                "$this->url/collections/$this->collection/points/search",
                ['json' => $body]
            );
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Qdrant search failed: {$e->getMessage()}", 0, $e);
        }

        $data    = json_decode((string) $response->getBody(), true);
        $results = $data['result'] ?? [];

        if (empty($results)) {
            return "No results found for query: $query";
        }

        $lines = ["Found " . count($results) . " result(s):\n"];

        foreach ($results as $i => $hit) {
            $payload = $hit['payload'];
            $score   = round($hit['score'], 3);
            $lines[] = sprintf(
                "[%d] Document: %s | Section: %s | Score: %s\n%s",
                $i + 1,
                $payload['document_name'] ?? 'unknown',
                $payload['section_title'] ?? 'unknown',
                $score,
                $payload['content'] ?? ''
            );
        }

        return implode("\n\n", $lines);
    }
}
