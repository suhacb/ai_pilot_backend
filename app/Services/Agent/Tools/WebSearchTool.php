<?php

namespace App\Services\Agent\Tools;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class WebSearchTool
{
    public function __construct(
        private readonly Client $client,
        private readonly string $url,
    ) {}

    /**
     * Search the web via the local SearXNG instance.
     *
     * Params:
     *   query  string  — required
     */
    public function execute(array $params): string
    {
        $query = $params['query'];

        try {
            $response = $this->client->get("$this->url/search", [
                'query' => [
                    'q'      => $query,
                    'format' => 'json',
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("SearXNG search failed: {$e->getMessage()}", 0, $e);
        }

        $data    = json_decode((string) $response->getBody(), true);
        $results = $data['results'] ?? [];

        if (empty($results)) {
            return "No results found for web query: $query";
        }

        $lines = ["Found " . count($results) . " web result(s):\n"];

        foreach ($results as $i => $result) {
            $lines[] = sprintf(
                "[%d] %s\nURL: %s\n%s",
                $i + 1,
                $result['title'] ?? 'No title',
                $result['url']   ?? '',
                $result['content'] ?? ''
            );
        }

        return implode("\n\n", $lines);
    }
}
