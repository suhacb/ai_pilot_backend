<?php

namespace App\Services\Agent;

use Illuminate\Support\Facades\Log;

class QueryExpander
{
    public function __construct(
        private readonly OllamaClient $ollama,
    ) {}

    /**
     * Generate $count alternative phrasings for multi-query retrieval.
     * Always returns the original query as the first element.
     * Falls back to [$query] on any failure.
     *
     * @return non-empty-list<string>
     */
    public function expand(string $query, string $model, int $count = 1): array
    {
        $prompt = <<<PROMPT
Generiraj {$count} alternativno iskalno poizvedbo za spodnje vprašanje. Namen je pokriti različne vidike z drugačnimi besedami ali sinonimi za boljše iskanje po dokumentih.

Izvirna poizvedba: {$query}

Zahteve:
- Alternativa išče enako informacijo z drugačnimi besedami ali poudarki
- Piši v slovenščini
- Kratka, natančna poizvedba (5–15 besed)
- Ne ponovi izvirne poizvedbe

Odgovori IZKLJUČNO z veljavnim JSON:
{"queries": ["alternativa"]}
PROMPT;

        try {
            $raw  = $this->ollama->generate($prompt, $model, true);
            $data = json_decode(trim($raw), true);
            $alts = array_values(array_filter(
                array_slice($data['queries'] ?? [], 0, $count),
                fn($q) => is_string($q) && trim($q) !== '' && trim($q) !== trim($query)
            ));
        } catch (\Throwable $e) {
            Log::debug('[QueryExpander] Expansion failed, using original only', ['error' => $e->getMessage()]);
            return [$query];
        }

        $queries = array_values(array_unique(array_merge([$query], $alts)));
        Log::debug('[QueryExpander] Expanded query', ['original' => $query, 'all' => $queries]);
        return $queries;
    }
}
