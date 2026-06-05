<?php

namespace App\Services\Agent;

use App\Exceptions\GenerationCancelledException;
use App\Models\AgentSession;
use App\Models\AgentStep;
use App\Services\Agent\Tools\GetDocumentTool;
use App\Services\Agent\Tools\WebSearchTool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AgentOrchestrator
{
    public function __construct(
        private readonly OllamaClient $ollama,
        private readonly RagRetriever $retriever,
        private readonly WebSearchTool $webSearch,
        private readonly GetDocumentTool $getDocument,
        private readonly int $maxIterations,
    ) {}

    /**
     * Run the ReAct loop for a given query and session.
     *
     * Yields step events during tool calls and a final answer event on completion.
     *
     * Event shapes:
     *   ['type' => 'step',   'reasoning' => '...', 'action_tool' => '...', 'action_params' => [...], 'observation' => '...']
     *   ['type' => 'answer', 'content' => '...']
     */
    public function run(string $query, AgentSession $session): \Generator
    {
        $planningModel   = $session->model_planning ?? $session->model_generative;
        $generativeModel = $session->model_generative;
        $history         = $session->conversation_history ?? [];

        $cacheKey = "agent:cancelled:{$session->id}";
        $this->ollama->setCancelCheck(fn () => Cache::has($cacheKey));

        try {
        $relevance = $this->assessRelevance($query, $history, $planningModel);

        if ($relevance['level'] === 'LOW') {
            yield ['type' => 'answer', 'content' => $this->offTopicResponse($query, $generativeModel)];
            return;
        }

        if ($relevance['level'] === 'MEDIUM') {
            $this->appendTurn($session, $query, $relevance['message'], $history);
            yield ['type' => 'answer', 'content' => $relevance['message']];
            return;
        }

        if ($planningModel === $generativeModel) {
            yield ['type' => 'step', 'message' => "Za odgovor na vaše vprašanje bom uporabil model {$generativeModel}."];
        } else {
            yield ['type' => 'step', 'message' => "Za načrtovanje korakov bom uporabil model {$planningModel}, za sintezo končnega odgovora pa model {$generativeModel}."];
        }

        // Planning context accumulates the full ReAct loop (including JSON instructions).
        // Evidence is tracked separately so the synthesis prompt stays free of JSON instructions.
        $planningContext  = $this->buildSystemPrompt($history);
        $planningContext .= "\n\nVprašanje uporabnika: " . $query . "\n";
        $evidence         = [];

        $seenHashes = [];

        for ($i = 0; $i < $this->maxIterations; $i++) {
            $suffix  = $i === 0
                ? "\nNavedi svoj prvi korak kot JSON:"
                : "\nNavedi naslednji korak kot JSON:";
            $prompt  = $planningContext . $suffix;
            $raw     = $this->ollama->generate($prompt, $planningModel, true);
            $step    = $this->parseResponse($raw, $i);

            Log::debug('[Agent] Step ' . $i, [
                'session'        => $session->id,
                'model'          => $planningModel,
                'context_length' => mb_strlen($prompt),
                'action'         => $step['action'] ?? 'missing',
                'raw_excerpt'    => mb_substr($raw, 0, 300),
            ]);

            if (($step['action'] ?? '') === 'finish') {
                $this->persistStep($session, $i, $step['reasoning'] ?? '', null, null, null, $raw, mb_strlen($prompt));
                if ($planningModel !== $generativeModel) {
                    yield ['type' => 'step', 'message' => "Preklapljam na model {$generativeModel} za sintezo končnega odgovora."];
                }
                $answer = $this->synthesise($query, $evidence, $generativeModel);
                $this->appendTurn($session, $query, $answer, $history);
                yield ['type' => 'answer', 'content' => $answer];
                return;
            }

            $tool        = $step['action'];
            $params      = $step['parameters'] ?? [];
            $observation = $this->dispatchTool($tool, $params, $planningModel);

            $obsHash = md5($observation);
            if (isset($seenHashes[$obsHash])) {
                Log::debug('[Agent] Skipping duplicate observation', ['step' => $i, 'tool' => $tool]);
                $planningContext .= sprintf(
                    "\nKorak %d:\nRazmišljanje: %s\nDejanje: %s(%s)\nOpazovanje: (enako kot prejšnji rezultat — preskočeno)\n",
                    $i + 1, $step['reasoning'] ?? '', $tool, json_encode($params, JSON_UNESCAPED_UNICODE)
                );
                yield ['type' => 'step', 'message' => $this->stepMessage($tool, $params)];
                continue;
            }
            $seenHashes[$obsHash] = true;

            $evidence[] = [
                'tool'        => $tool,
                'params'      => $params,
                'observation' => $observation,
            ];

            $planningNote = $this->planningNoteFor($tool, $params, $observation);

            $this->persistStep($session, $i, $step['reasoning'] ?? '', $tool, $params, $planningNote, $raw, mb_strlen($prompt));

            $planningContext .= sprintf(
                "\nKorak %d:\nRazmišljanje: %s\nDejanje: %s(%s)\nOpazovanje: %s\n",
                $i + 1,
                $step['reasoning'] ?? '',
                $tool,
                json_encode($params, JSON_UNESCAPED_UNICODE),
                $planningNote
            );

            yield [
                'type'    => 'step',
                'message' => $this->stepMessage($tool, $params),
            ];
        }

        // Max iterations reached — synthesise from whatever was gathered
        if ($planningModel !== $generativeModel) {
            yield ['type' => 'step', 'message' => "Preklapljam na model {$generativeModel} za sintezo končnega odgovora."];
        }
        $answer = $this->synthesise($query, $evidence, $generativeModel);
        $this->appendTurn($session, $query, $answer, $history);
        yield ['type' => 'answer', 'content' => $answer];
        } catch (GenerationCancelledException) {
            yield ['type' => 'cancelled', 'message' => 'Generiranje je bilo prekinjeno.'];
        } catch (\Throwable $e) {
            Log::error('[Agent] Unhandled exception in ReAct loop', [
                'session' => $session->id,
                'error'   => $e->getMessage(),
                'class'   => get_class($e),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            yield ['type' => 'error', 'message' => 'Prišlo je do napake pri obdelavi vašega vprašanja. Prosimo, poskusite znova.'];
        } finally {
            $this->ollama->setCancelCheck(null);
            Cache::forget($cacheKey);
        }
    }

    private function synthesise(string $query, array $evidence, string $model): string
    {
        $maxEvidenceChars = $this->maxEvidenceChars($this->ollama->contextWindowFor($model));

        return $this->ollama->generate(
            $this->buildSynthesisPrompt($query, $evidence, $maxEvidenceChars),
            $model,
            false
        );
    }

    private function dispatchTool(string $tool, array $params, string $planningModel): string
    {
        return match ($tool) {
            'search_semantic' => $this->retriever->semanticSearch($params, $planningModel),
            'search_fulltext' => $this->retriever->fulltextSearch($params, $planningModel),
            'search_web'      => $this->webSearch->execute($params),
            'get_document'    => $this->getDocument->execute($params),
            default           => "Unknown tool: $tool",
        };
    }

    private function parseResponse(string $raw, int $stepIndex = -1): array
    {
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $cleaned);
        $cleaned = preg_replace('/```\s*$/m', '', $cleaned);
        $cleaned = trim($cleaned);

        $data = json_decode($cleaned, true);

        if (!is_array($data)) {
            if (preg_match('/\{.*\}/s', $cleaned, $matches)) {
                $data = json_decode($matches[0], true);
            }
        }

        if (!is_array($data)) {
            Log::warning('[Agent] Could not parse LLM output as JSON', [
                'step' => $stepIndex,
                'raw'  => mb_substr($raw, 0, 600),
            ]);
            return ['reasoning' => 'Failed to parse LLM response.', 'action' => 'finish', 'parameters' => []];
        }

        // Recover from common alternative key names the model sometimes uses
        if (empty($data['action'])) {
            $data['action'] = $data['tool'] ?? $data['tool_name'] ?? $data['function'] ?? $data['act'] ?? null;

            if (empty($data['action']) && isset($data['function_call']['name'])) {
                $data['action']     = $data['function_call']['name'];
                $data['parameters'] = $data['parameters'] ?? $data['function_call']['arguments'] ?? [];
            }
        }

        if (empty($data['action'])) {
            Log::warning('[Agent] LLM response has no recognisable action field', [
                'step'   => $stepIndex,
                'parsed' => $data,
                'raw'    => mb_substr($raw, 0, 600),
            ]);
            return array_merge($data, ['action' => 'finish', 'parameters' => []]);
        }

        return $data;
    }

    private function persistStep(
        AgentSession $session,
        int $index,
        string $reasoning,
        ?string $tool,
        ?array $params,
        ?string $observation,
        ?string $rawLlmResponse = null,
        ?int $contextLength = null,
    ): void {
        AgentStep::create([
            'session_id'       => $session->id,
            'step_index'       => $index,
            'reasoning'        => $reasoning,
            'action_tool'      => $tool,
            'action_params'    => $params,
            'observation'      => $observation,
            'raw_llm_response' => $rawLlmResponse,
            'context_length'   => $contextLength,
        ]);
    }

    /**
     * Generate a short session title (≤ 5 words) from the user's first prompt.
     * Falls back to a truncated version of the prompt on any failure.
     */
    public function generateTitle(string $prompt, string $model): string
    {
        $titlePrompt = <<<PROMPT
Napiši kratek naslov (največ 5 besed) za pogovor, ki se začne z naslednjim vprašanjem. Naslov mora biti jedrnat in opisati temo. Odgovori SAMO z naslovom, brez narekovajev, pik ali dodatnega besedila.

Vprašanje: {$prompt}

Naslov:
PROMPT;

        try {
            $title = trim($this->ollama->generate($titlePrompt, $model, false));
            return mb_substr($title, 0, 100);
        } catch (\Throwable) {
            return mb_substr($prompt, 0, 60);
        }
    }

    /**
     * Assess the relevance of a query to the system's domain.
     *
     * Returns ['level' => 'HIGH'|'MEDIUM'|'LOW', 'message' => string|null].
     * For MEDIUM, 'message' is a ready-to-send clarification in the user's language.
     * For HIGH and LOW, 'message' is null.
     *
     * @param  array<array{user: string, agent: string}>  $history
     */
    private function assessRelevance(string $query, array $history, string $model): array
    {
        $contextBlock = '';
        if (!empty($history)) {
            $recent = array_slice($history, -2);
            $lines  = [];
            foreach ($recent as $turn) {
                $lines[] = 'Uporabnik: ' . $turn['user'];
                $lines[] = 'Asistent: ' . mb_substr($turn['agent'], 0, 200) . (mb_strlen($turn['agent']) > 200 ? '…' : '');
            }
            $contextBlock = "\nPredhodni pogovor (za kontekst):\n" . implode("\n", $lines) . "\n";
        }

        $prompt = <<<PROMPT
Si klasifikator poizvedb za AI svetovalca za informacijsko varnost in skladnost z ZInfV-1.
{$contextBlock}
Sistem obravnava:
- Informacijska varnost: sumljiva e-pošta, phishing, zlonamerna koda, vdori, nepooblaščen dostop
- Varnostni incidenti in odziv nanje
- Skladnost z ZInfV-1 in drugimi predpisi
- Interne varnostne politike in postopki
- Ocenjevanje tveganj dobaviteljev
- Fizična varnost, varstvo osebnih podatkov
- Vprašanja o vsebini internih dokumentov tega podjetja

Poizvedba: {$query}

Oceni relevantnost poizvedbe:
- HIGH — poizvedba se jasno nanaša na informacijsko varnost, varnostne incidente, skladnost ali sorodna področja
- MEDIUM — poizvedba bi lahko spadala v področje, a je dvoumna, prekratka ali premalo specifična; koristilo bi pojasnilo
- LOW — poizvedba jasno ne spada v področje (npr. vreme, kuhanje, geografija, zabava)

Odgovori z veljavnim JSON v točno tem formatu (brez dodatnega besedila):
{
  "level": "HIGH",
  "message": null
}

Za MEDIUM vnesi v "message" prijazen odgovor v jeziku poizvedbe, ki pojasni, kaj je nejasno, in prosi za dodatne podrobnosti.
Za HIGH in LOW pusti "message" kot null.
PROMPT;

        $raw  = $this->ollama->generate($prompt, $model, true);
        $data = json_decode(trim($raw), true);

        $level = strtoupper($data['level'] ?? '');
        if (!in_array($level, ['HIGH', 'MEDIUM', 'LOW'], true)) {
            Log::warning('[Agent] Unexpected relevance level, defaulting to HIGH', ['raw' => mb_substr($raw, 0, 300)]);
            $level = 'HIGH';
        }

        Log::debug('[Agent] Relevance assessment', ['query' => $query, 'level' => $level]);

        return [
            'level'   => $level,
            'message' => ($level === 'MEDIUM') ? ($data['message'] ?? null) : null,
        ];
    }

    private function offTopicResponse(string $query, string $model): string
    {
        $prompt = <<<PROMPT
Si asistent za informacijsko varnost v podjetju za fizično in tehnično varnost.

Uporabnik je postavil vprašanje, ki ne spada v področje informacijske varnosti, varnostnih politik ali skladnosti z zakonodajo:

Vprašanje: {$query}

Napiši kratek, prijazen odgovor (2–3 stavke), v katerem:
1. Pojasniš, da si specializiran za informacijsko varnost in skladnost z ZInfV-1.
2. Predlagaš, kam se lahko uporabnik obrne za odgovor na to vprašanje.

Odgovor napiši v istem jeziku kot vprašanje.
PROMPT;

        return $this->ollama->generate($prompt, $model, false);
    }

    /**
     * Persist this turn's (query, answer) pair into the session's conversation history.
     * Keeps the last 10 turns to bound context growth.
     *
     * @param  array<array{user: string, agent: string}>  $history
     */
    private function appendTurn(AgentSession $session, string $query, string $answer, array $history): void
    {
        $history[] = ['user' => $query, 'agent' => $answer];
        $session->update(['conversation_history' => array_slice($history, -10)]);
    }

    private function stepMessage(string $tool, array $params): string
    {
        return match ($tool) {
            'search_semantic' => 'Semantično iskanje: ' . ($params['query'] ?? ''),
            'search_fulltext' => 'Iskanje po ključnih besedah: ' . ($params['query'] ?? ''),
            'search_web'      => 'Spletno iskanje: ' . ($params['query'] ?? ''),
            'get_document'    => 'Pridobivam dokument: ' . ($params['document_name'] ?? ''),
            default           => 'Izvajam: ' . $tool,
        };
    }

    /**
     * @param  array<array{user: string, agent: string}>  $history
     */
    private function buildSystemPrompt(array $history = []): string
    {
        $tools = json_encode($this->toolDefinitions(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $historyBlock = '';
        if (!empty($history)) {
            $lines = [];
            foreach (array_slice($history, -3) as $turn) {
                $lines[] = 'Uporabnik: ' . $turn['user'];
                $lines[] = 'Asistent: ' . mb_substr($turn['agent'], 0, 400) . (mb_strlen($turn['agent']) > 400 ? '…' : '');
            }
            $historyBlock = "\n\n=== Predhodni pogovor ===\n" . implode("\n", $lines) . "\n=== Konec predhodnega pogovora ===";
        }

        return <<<PROMPT
Si AI svetovalec za skladnost v podjetju za fizično in tehnično varnost, ki je zavezano ZInfV-1 (Zakon o informacijski varnosti). Pomagaš pri treh vrstah vprašanj:
1. Analiza skladnostnih vrzeli — ugotavljanje, kaj je zahtevano in kaj je dokumentirano
2. Načrtovanje odziva na incidente — razvrstitev incidentov in priprava akcijskega načrta
3. Ocena tveganja dobaviteljev — vrednotenje tveganj tretjih oseb glede na interne kriterije in ZInfV-1

Na voljo imaš naslednja orodja:

{$tools}

{$historyBlock}

Vedno razmišljaj korak za korakom, preden ukrepaš. Uporabi orodja za zbiranje dokazov, preden sklepaš. V odgovoru navedi konkretne dokumente in številke členov.

Strategija iskanja:
- Iskalne poizvedbe VEDNO piši v slovenščini — vsi dokumenti so v slovenščini, zato bo ujemanje najboljše.
- **Faza 1 – Obvezno dvojno iskanje:** Za VSAKO vprašanje MORAŠ izvesti OBA spodnja klica, preden narediš karkoli drugega:
  1. `search_semantic` — semantično iskanje za konceptualno ujemanje
  2. `search_fulltext` — ključnobesedno iskanje za točne izraze in številke členov
  Šele ko sta oba klica zaključena, smeš uporabiti get_document ali zaključiti.
- **Faza 2 – Pridobitev dokumenta:** Če je kateri dokument jasno relevanten (visoka ocena ali smiselno ime), uporabi get_document za pridobitev celotnega dokumenta ali konkretnega razdelka. Uporabi natanko tisto ime dokumenta, kot je prikazano v rezultatih iskanja.
- **Širše iskanje:** Nadaljuj z dodatnimi iskanji, če noben dokument ni dovolj jasen zadetek, ali če vprašanje zahteva analizo več virov.
- Za praktična operativna vprašanja (gesla, dostopi, varnostne kopije, incidenti, dobavitelji): dodaj filter `internal_policy` pri search_semantic.
- Za vprašanja o regulativni skladnosti (zakonske obveznosti, zahteve členov): dodaj filter `legislation` pri search_semantic.
- Za analizo vrzeli: ne filtriraj — išči po vseh virih.
- Ne ponavljaj istega iskanja dvakrat.

POMEMBNO: Odgovori IZKLJUČNO z veljavnim JSON. Brez besedila zunaj JSON objekta. Uporabi natanko ta format:

{
  "reasoning": "Tvoje razmišljanje korak za korakom o naslednjem dejanju",
  "action": "ime_orodja",
  "parameters": { ... }
}

Ko si zbral dovolj informacij, uporabi action "finish":

{
  "reasoning": "Povzetek tega, kar si ugotovil",
  "action": "finish",
  "parameters": {}
}
PROMPT;
    }

    /**
     * Build a clean synthesis prompt that contains ONLY the search evidence and the query.
     * Must never include the planning system prompt, which instructs the model to respond in JSON.
     *
     * @param  array<array{tool: string, params: array, observation: string}>  $evidence
     */
    private function buildSynthesisPrompt(string $query, array $evidence, int $maxEvidenceChars = 20000): string
    {
        if (empty($evidence)) {
            $evidenceText = 'Iskanje ni vrnilo rezultatov.';
        } else {
            $parts = [];
            foreach ($evidence as $idx => $e) {
                $parts[] = sprintf(
                    "--- Najdeni odlomki %d (orodje: %s, parametri: %s) ---\n%s",
                    $idx + 1,
                    $e['tool'],
                    json_encode($e['params'], JSON_UNESCAPED_UNICODE),
                    $this->truncateObservation($e['observation'], (int)($maxEvidenceChars / max(1, count($evidence))))
                );
            }
            $evidenceText = implode("\n\n", $parts);
        }

        return <<<PROMPT
Si strokovni svetovalec za informacijsko varnost in skladnost z ZInfV-1 v podjetju za fizično in tehnično varnost.

Na podlagi spodnjih odlomkov iz internih dokumentov in zakonodaje pripravi jasen, strukturiran odgovor na vprašanje. Upoštevaj naslednja pravila:
- Odgovor mora temeljiti izključno na prikazanih odlomkih.
- Navedi konkretne dokumente ali člene, na katere se sklicuješ.
- Strukturiraj odgovor z naslovi in alinejami, kjer je smiselno.
- Odgovarjaj v istem jeziku kot vprašanje.
- Ne izmišljuj informacij, ki jih v odlomkih ni.

Najdeni odlomki:

{$evidenceText}

Vprašanje: {$query}

Odgovor:
PROMPT;
    }

    /**
     * Compact summary of a tool result for the planning context.
     *
     * The planning model only needs to know *what was found* (document names, scores,
     * result count) to decide its next action — it does not need the full chunk text.
     * Full text is preserved in $evidence[] and reaches the model only at synthesis time,
     * keeping the planning context small and eliminating the need for LLM-based condensation.
     */
    private function planningNoteFor(string $tool, array $params, string $observation): string
    {
        return match ($tool) {
            'search_semantic', 'search_fulltext' => $this->extractSearchHeaders($observation),
            default => mb_substr($observation, 0, 300) . (mb_strlen($observation) > 300 ? '…' : ''),
        };
    }

    /**
     * Extract only the header lines from a RagRetriever-formatted search result.
     * Each header is "[N] Dokument: X | Razdelek: Y | Score" — one line per chunk.
     * The full content lines are dropped; they go to $evidence[] instead.
     */
    private function extractSearchHeaders(string $observation): string
    {
        preg_match_all('/^\[\d+\] Dokument:.*$/m', $observation, $matches);

        if (empty($matches[0])) {
            return mb_substr($observation, 0, 200);
        }

        $firstLine = trim(explode("\n", $observation)[0]);
        return $firstLine . "\n" . implode("\n", $matches[0]);
    }

    /**
     * Maximum total characters of evidence passed to the synthesis prompt.
     * Reserves 40% of the context for the system prompt, query, and generated answer.
     */
    private function maxEvidenceChars(int $contextWindow): int
    {
        return max(4000, (int)($contextWindow * 0.6 * 3.5));
    }

    private function truncateObservation(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }
        return mb_substr($text, 0, $maxChars) . "\n[... skrčeno — preseže okno konteksta ...]";
    }

    private function toolDefinitions(): array
    {
        return [
            [
                'name'        => 'search_semantic',
                'description' => 'Išči po zbirki znanja z semantično podobnostjo. Najboljše za konceptualna vprašanja, iskanje politik ali ko točno besedilo ni znano. Poizvedbo VEDNO napiši v slovenščini. Rezultati vsebujejo ime dokumenta (document_name) in oceno ujemanja (score) — uporabi jih za odločitev o get_document.',
                'parameters'  => [
                    'query'              => 'string — iskalna poizvedba v slovenščini',
                    'top_k'              => 'integer — število rezultatov (privzeto 5)',
                    'filter_source_type' => 'string — neobvezno: internal_policy | legislation',
                ],
            ],
            [
                'name'        => 'search_fulltext',
                'description' => 'Išči po zbirki znanja z BM25 ključnimi besedami. Najboljše za iskanje konkretnih številk členov, definiranih izrazov ali točnih besednih zvez. Poizvedbo VEDNO napiši v slovenščini. Rezultati vsebujejo ime dokumenta (document_name) — uporabi ga za get_document.',
                'parameters'  => [
                    'query' => 'string — ključne besede ali besedna zveza v slovenščini',
                    'top_k' => 'integer — število rezultatov (privzeto 5)',
                ],
            ],
            [
                'name'        => 'search_web',
                'description' => 'Išči po javnem spletu prek lokalnega SearXNG. Uporabi za aktualne regulativne smernice, javne informacije o dobaviteljih ali nedavne incidente.',
                'parameters'  => [
                    'query' => 'string — iskalna poizvedba',
                ],
            ],
            [
                'name'        => 'get_document',
                'description' => 'Pridobi celoten dokument ali konkreten razdelek. Uporabi po iskanju, ko je jasno kateri dokument vsebuje relevantne informacije. Ime dokumenta (document_name) mora biti natanko tako, kot je prikazano v rezultatih iskanja.',
                'parameters'  => [
                    'document_name' => 'string — ime dokumenta, kot je prikazano v rezultatih iskanja',
                    'section_title' => 'string — neobvezno, ime razdelka za ožjo pridobitev',
                ],
            ],
        ];
    }
}
