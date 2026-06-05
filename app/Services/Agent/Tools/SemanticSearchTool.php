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
     * Execute a single semantic search and return a formatted string for the agent.
     * Used as the direct tool call and as the fallback path inside RagRetriever.
     */
    public function execute(array $params): string
    {
        $query      = $params['query'];
        $topK       = (int)($params['top_k'] ?? 5);
        $sourceType = $params['filter_source_type'] ?? null;

        $chunks = $this->search($query, $topK, $sourceType);

        if (empty($chunks)) {
            return "Ni rezultatov za poizvedbo: {$query}";
        }

        $lines = ["Najdenih " . count($chunks) . " rezultat(ov):\n"];

        foreach ($chunks as $i => $chunk) {
            $lines[] = sprintf(
                "[%d] Dokument: %s | Razdelek: %s | Ocena: %.3f\n%s",
                $i + 1,
                $chunk['document_name'],
                $chunk['section_title'] ?? 'neznano',
                $chunk['search_score'],
                $chunk['content']
            );
        }

        return implode("\n\n", $lines);
    }

    /**
     * Run a single Qdrant similarity search and return structured chunk data.
     * Used by RagRetriever for multi-query merging.
     *
     * @return array<array{document_name: string, section_title: string|null, content: string, search_score: float}>
     */
    public function search(string $query, int $topK, ?string $sourceType): array
    {
        $vector = $this->embedder->embed($query);

        $body = [
            'vector'       => $vector,
            'limit'        => $topK,
            'with_payload' => true,
        ];

        if ($sourceType !== null) {
            $body['filter'] = [
                'must' => [['key' => 'source_type', 'match' => ['value' => $sourceType]]],
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

        $data = json_decode((string) $response->getBody(), true);

        return array_map(fn($hit) => [
            'document_name' => $hit['payload']['document_name'] ?? 'neznano',
            'section_title' => $hit['payload']['section_title'] ?? null,
            'content'       => $hit['payload']['content'] ?? '',
            'search_score'  => (float)($hit['score'] ?? 0.0),
        ], $data['result'] ?? []);
    }
}
