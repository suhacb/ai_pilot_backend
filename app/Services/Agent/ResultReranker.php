<?php

namespace App\Services\Agent;

use Illuminate\Support\Facades\Log;

class ResultReranker
{
    public function __construct(
        private readonly OllamaClient $ollama,
    ) {}

    /**
     * Score each chunk's relevance to $query in a single LLM batch call.
     * Returns chunks sorted by relevance_score descending, excluding score 0.
     * Falls back to original order (all scored 1) on any failure.
     *
     * Each input chunk must contain: document_name, section_title, content.
     * Each output chunk gains a 'relevance_score' key (int 0–3).
     *
     * @param  array<array{document_name: string, section_title: string|null, content: string}>  $chunks
     * @return array
     */
    public function rank(array $chunks, string $query, string $model): array
    {
        if (empty($chunks)) {
            return [];
        }

        $snippets = [];
        foreach ($chunks as $i => $chunk) {
            $snippets[] = sprintf(
                "[%d] Dokument: %s | Razdelek: %s\n%s",
                $i + 1,
                $chunk['document_name'] ?? 'neznano',
                $chunk['section_title'] ?? '',
                mb_substr($chunk['content'] ?? '', 0, 350)
            );
        }

        $count = count($chunks);
        $text  = implode("\n\n", $snippets);

        $prompt = <<<PROMPT
Oceni relevantnost vsakega odlomka glede na vprašanje.

Lestvica:
0 = ni relevantno
1 = posredno relevantno
2 = relevantno
3 = zelo relevantno, neposredno odgovarja na vprašanje

Vprašanje: {$query}

Odlomki:
{$text}

Odgovori IZKLJUČNO z veljavnim JSON — točno {$count} celih ocen v enakem vrstnem redu kot odlomki:
{"scores": [2, 0, 3]}
PROMPT;

        try {
            $raw    = $this->ollama->generate($prompt, $model, true);
            $data   = json_decode(trim($raw), true);
            $scores = $data['scores'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('[Reranker] Scoring failed, returning unranked', ['error' => $e->getMessage()]);
            return array_map(fn($c) => array_merge($c, ['relevance_score' => 1]), $chunks);
        }

        foreach ($chunks as $i => &$chunk) {
            $chunk['relevance_score'] = isset($scores[$i]) ? max(0, min(3, (int)$scores[$i])) : 1;
        }
        unset($chunk);

        Log::debug('[Reranker] Scores assigned', ['query' => $query, 'scores' => $scores]);

        usort($chunks, fn($a, $b) => ($b['relevance_score'] ?? 0) <=> ($a['relevance_score'] ?? 0));

        return array_values(array_filter($chunks, fn($c) => ($c['relevance_score'] ?? 0) >= 1));
    }
}
