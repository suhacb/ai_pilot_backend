<?php

namespace App\Services\Agent;

use App\Services\Agent\Tools\FulltextSearchTool;
use App\Services\Agent\Tools\SemanticSearchTool;
use Illuminate\Support\Facades\Log;

class RagRetriever
{
    private const MAX_RERANK_CANDIDATES = 12;

    public function __construct(
        private readonly SemanticSearchTool $semanticSearch,
        private readonly FulltextSearchTool $fulltextSearch,
        private readonly QueryExpander $expander,
        private readonly ResultReranker $reranker,
    ) {}

    /**
     * Multi-query semantic search with LLM reranking.
     * Falls back to single-query without reranking on any failure.
     */
    public function semanticSearch(array $params, string $model): string
    {
        $query      = $params['query'] ?? '';
        $topK       = (int)($params['top_k'] ?? 5);
        $sourceType = $params['filter_source_type'] ?? null;

        try {
            $queries = $this->expander->expand($query, $model);
            $merged  = $this->mergeSemanticResults($queries, $topK, $sourceType);
            $ranked  = $this->reranker->rank($merged, $query, $model);

            Log::debug('[RagRetriever] semanticSearch complete', [
                'queries'       => $queries,
                'before_rerank' => count($merged),
                'after_rerank'  => count($ranked),
            ]);

            return empty($ranked)
                ? "Ni rezultatov za poizvedbo: {$query}"
                : $this->format($ranked, count($queries) > 1);
        } catch (\Throwable $e) {
            Log::warning('[RagRetriever] Enhanced semantic search failed, falling back', ['error' => $e->getMessage()]);
            return $this->semanticSearch->execute($params);
        }
    }

    /**
     * Multi-query fulltext (BM25) search with LLM reranking.
     * Falls back to single-query without reranking on any failure.
     */
    public function fulltextSearch(array $params, string $model): string
    {
        $query = $params['query'] ?? '';
        $topK  = (int)($params['top_k'] ?? 5);

        try {
            $queries = $this->expander->expand($query, $model);
            $merged  = $this->mergeFulltextResults($queries, $topK);
            $ranked  = $this->reranker->rank($merged, $query, $model);

            Log::debug('[RagRetriever] fulltextSearch complete', [
                'queries'       => $queries,
                'before_rerank' => count($merged),
                'after_rerank'  => count($ranked),
            ]);

            return empty($ranked)
                ? "Ni rezultatov za poizvedbo: {$query}"
                : $this->format($ranked, count($queries) > 1);
        } catch (\Throwable $e) {
            Log::warning('[RagRetriever] Enhanced fulltext search failed, falling back', ['error' => $e->getMessage()]);
            return $this->fulltextSearch->execute($params);
        }
    }

    /**
     * Run each query against Qdrant, merge, and deduplicate by content hash.
     * Keeps the best search score per unique chunk.
     */
    private function mergeSemanticResults(array $queries, int $topK, ?string $sourceType): array
    {
        $byHash = [];

        foreach ($queries as $q) {
            foreach ($this->semanticSearch->search($q, $topK, $sourceType) as $chunk) {
                $key = md5($chunk['content'] ?? '');
                if (!isset($byHash[$key]) || ($chunk['search_score'] ?? 0) > ($byHash[$key]['search_score'] ?? 0)) {
                    $byHash[$key] = $chunk;
                }
            }
        }

        $merged = array_values($byHash);
        usort($merged, fn($a, $b) => ($b['search_score'] ?? 0) <=> ($a['search_score'] ?? 0));

        return array_slice($merged, 0, self::MAX_RERANK_CANDIDATES);
    }

    /**
     * Run each query against ZincSearch, merge, and deduplicate by content hash.
     */
    private function mergeFulltextResults(array $queries, int $topK): array
    {
        $byHash = [];

        foreach ($queries as $q) {
            foreach ($this->fulltextSearch->search($q, $topK) as $chunk) {
                $key = md5($chunk['content'] ?? '');
                if (!isset($byHash[$key])) {
                    $byHash[$key] = $chunk;
                }
            }
        }

        return array_slice(array_values($byHash), 0, self::MAX_RERANK_CANDIDATES);
    }

    private function format(array $chunks, bool $multiQuery): string
    {
        $suffix = $multiQuery ? ' (multi-poizvedbeno iskanje z rerankiranjem)' : '';
        $lines  = ["Najdenih " . count($chunks) . " relevantnih rezultat(ov){$suffix}:\n"];

        foreach ($chunks as $i => $chunk) {
            $scoreStr = isset($chunk['relevance_score'])
                ? "Relevantnost: {$chunk['relevance_score']}/3"
                : sprintf("Ocena: %.3f", $chunk['search_score'] ?? 0.0);

            $lines[] = sprintf(
                "[%d] Dokument: %s | Razdelek: %s | %s\n%s",
                $i + 1,
                $chunk['document_name'] ?? 'neznano',
                $chunk['section_title'] ?? 'neznano',
                $scoreStr,
                $chunk['content'] ?? ''
            );
        }

        return implode("\n\n", $lines);
    }
}
