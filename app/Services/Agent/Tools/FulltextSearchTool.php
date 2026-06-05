<?php

namespace App\Services\Agent\Tools;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class FulltextSearchTool
{
    public function __construct(
        private readonly Client $client,
        private readonly string $url,
        private readonly string $index,
        private readonly string $user,
        private readonly string $password,
    ) {}

    /**
     * Execute a single BM25 search and return a formatted string for the agent.
     * Used as the direct tool call and as the fallback path inside RagRetriever.
     */
    public function execute(array $params): string
    {
        $query = $params['query'];
        $topK  = (int)($params['top_k'] ?? 5);

        $chunks = $this->search($query, $topK);

        if (empty($chunks)) {
            return "Ni rezultatov za poizvedbo: {$query}";
        }

        $lines = ["Najdenih " . count($chunks) . " rezultat(ov):\n"];

        foreach ($chunks as $i => $chunk) {
            $lines[] = sprintf(
                "[%d] Dokument: %s | Razdelek: %s\n%s",
                $i + 1,
                $chunk['document_name'],
                $chunk['section_title'] ?? 'neznano',
                $chunk['content']
            );
        }

        return implode("\n\n", $lines);
    }

    /**
     * Run a single ZincSearch BM25 query and return structured chunk data.
     * Used by RagRetriever for multi-query merging.
     *
     * @return array<array{document_name: string, section_title: string|null, content: string, search_score: float}>
     */
    public function search(string $query, int $topK): array
    {
        try {
            $response = $this->client->post("$this->url/api/$this->index/_search", [
                'auth' => [$this->user, $this->password],
                'json' => [
                    'search_type' => 'match',
                    'query'       => ['term' => $query],
                    'max_results' => $topK,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("ZincSearch query failed: {$e->getMessage()}", 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        $hits = $data['hits']['hits'] ?? [];

        return array_map(fn($hit) => [
            'document_name' => $hit['_source']['document_name'] ?? 'neznano',
            'section_title' => $hit['_source']['section_title'] ?? null,
            'content'       => $hit['_source']['content'] ?? '',
            'search_score'  => (float)($hit['_score'] ?? 0.0),
        ], $hits);
    }
}
