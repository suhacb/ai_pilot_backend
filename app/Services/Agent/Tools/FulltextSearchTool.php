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
     * Search the full-text index using BM25 keyword matching.
     *
     * Params:
     *   query   string  — required
     *   top_k   int     — optional, default 5
     */
    public function execute(array $params): string
    {
        $query = $params['query'];
        $topK  = (int) ($params['top_k'] ?? 5);

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

        if (empty($hits)) {
            return "No results found for query: $query";
        }

        $lines = ["Found " . count($hits) . " result(s):\n"];

        foreach ($hits as $i => $hit) {
            $source = $hit['_source'];
            $lines[] = sprintf(
                "[%d] Document: %s | Section: %s\n%s",
                $i + 1,
                $source['document_name'] ?? 'unknown',
                $source['section_title'] ?? 'unknown',
                $source['content'] ?? ''
            );
        }

        return implode("\n\n", $lines);
    }
}
