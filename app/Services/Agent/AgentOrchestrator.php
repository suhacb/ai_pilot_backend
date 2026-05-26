<?php

namespace App\Services\Agent;

use App\Models\AgentSession;
use App\Models\AgentStep;
use App\Services\Agent\Tools\FulltextSearchTool;
use App\Services\Agent\Tools\GetDocumentTool;
use App\Services\Agent\Tools\SemanticSearchTool;
use App\Services\Agent\Tools\WebSearchTool;

class AgentOrchestrator
{
    public function __construct(
        private readonly OllamaClient $ollama,
        private readonly SemanticSearchTool $semanticSearch,
        private readonly FulltextSearchTool $fulltextSearch,
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

        $context = $this->buildSystemPrompt();
        $context .= "\n\nUser query: " . $query . "\n";

        for ($i = 0; $i < $this->maxIterations; $i++) {
            $suffix = $i === 0
                ? "\nProvide your first step as JSON:"
                : "\nProvide your next step as JSON:";

            $raw  = $this->ollama->generate($context . $suffix, $planningModel, true);
            $step = $this->parseResponse($raw);

            if (($step['action'] ?? '') === 'finish') {
                $this->persistStep($session, $i, $step['reasoning'] ?? '', null, null, null);

                $synthesisPrompt = $this->buildSynthesisPrompt($query, $context);
                $finalAnswer     = $this->ollama->generate($synthesisPrompt, $generativeModel, false);

                yield ['type' => 'answer', 'content' => $finalAnswer];
                return;
            }

            $tool        = $step['action'] ?? 'unknown';
            $params      = $step['parameters'] ?? [];
            $observation = $this->dispatchTool($tool, $params);

            $this->persistStep($session, $i, $step['reasoning'] ?? '', $tool, $params, $observation);

            $context .= sprintf(
                "\nStep %d:\nReasoning: %s\nAction: %s(%s)\nObservation: %s\n",
                $i + 1,
                $step['reasoning'] ?? '',
                $tool,
                json_encode($params, JSON_UNESCAPED_UNICODE),
                $observation
            );

            yield [
                'type'          => 'step',
                'reasoning'     => $step['reasoning'] ?? '',
                'action_tool'   => $tool,
                'action_params' => $params,
                'observation'   => $observation,
            ];
        }

        yield ['type' => 'answer', 'content' => 'Maximum iterations reached. Partial answer based on gathered information.'];
    }

    private function dispatchTool(string $tool, array $params): string
    {
        return match ($tool) {
            'search_semantic'  => $this->semanticSearch->execute($params),
            'search_fulltext'  => $this->fulltextSearch->execute($params),
            'search_web'       => $this->webSearch->execute($params),
            'get_document'     => $this->getDocument->execute($params),
            default            => "Unknown tool: $tool",
        };
    }

    private function parseResponse(string $raw): array
    {
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $cleaned);
        $cleaned = preg_replace('/```\s*$/m', '', $cleaned);
        $cleaned = trim($cleaned);

        $data = json_decode($cleaned, true);

        if (!is_array($data)) {
            // Try to extract a JSON object from anywhere in the string
            if (preg_match('/\{.*\}/s', $cleaned, $matches)) {
                $data = json_decode($matches[0], true);
            }
        }

        if (!is_array($data)) {
            return [
                'reasoning'  => 'Failed to parse LLM response as JSON.',
                'action'     => 'finish',
                'parameters' => ['final_answer' => $raw],
            ];
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
    ): void {
        AgentStep::create([
            'session_id'    => $session->id,
            'step_index'    => $index,
            'reasoning'     => $reasoning,
            'action_tool'   => $tool,
            'action_params' => $params,
            'observation'   => $observation,
        ]);
    }

    private function buildSystemPrompt(): string
    {
        $tools = json_encode($this->toolDefinitions(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
You are an AI compliance advisor for a physical and technical security company subject to ZInfV-1 (Zakon o informacijski varnosti). You assist with three types of requests:
1. Compliance gap analysis — identifying what is required vs. what is documented
2. Incident response planning — classifying incidents and producing actionable response plans
3. Supplier risk assessment — evaluating third-party risk against internal criteria and ZInfV-1

You have access to the following tools:

{$tools}

Always reason step by step before acting. Use tools to gather evidence before drawing conclusions. Cite documents and article numbers in your final answer.

IMPORTANT: Respond ONLY with valid JSON. No text outside the JSON object. Use exactly this format:

{
  "reasoning": "Your step-by-step reasoning about what to do next",
  "action": "tool_name",
  "parameters": { ... }
}

When you have gathered sufficient information, use action "finish":

{
  "reasoning": "Your reasoning for the final answer",
  "action": "finish",
  "parameters": {
    "final_answer": "Your complete, structured answer with citations"
  }
}

Respond in the same language as the user's query. For Slovenian queries, answer in Slovenian.
PROMPT;
    }

    private function buildSynthesisPrompt(string $query, string $gatheredContext): string
    {
        return <<<PROMPT
You are an AI compliance advisor for a physical and technical security company subject to ZInfV-1.

Below is a research process that was performed to answer the user's query. Review all gathered information and synthesize a comprehensive, well-structured final answer.

{$gatheredContext}

Original query: {$query}

Write a clear, structured answer based on the above research. Cite specific documents and article numbers where relevant. Respond in the same language as the user's query (Slovenian queries require a Slovenian answer).
PROMPT;
    }

    private function toolDefinitions(): array
    {
        return [
            [
                'name'        => 'search_semantic',
                'description' => 'Search the compliance knowledge base using semantic similarity. Best for conceptual questions, finding related policies, or when exact wording is unknown.',
                'parameters'  => [
                    'query'              => 'string — the search query',
                    'top_k'              => 'integer — number of results (default 5)',
                    'filter_source_type' => 'string — optional: internal_policy | legislation',
                ],
            ],
            [
                'name'        => 'search_fulltext',
                'description' => 'Search the compliance knowledge base using BM25 keyword matching. Best for finding specific article numbers, defined terms, or exact phrases.',
                'parameters'  => [
                    'query' => 'string — keywords or phrase',
                    'top_k' => 'integer — number of results (default 5)',
                ],
            ],
            [
                'name'        => 'search_web',
                'description' => 'Search the public web via local SearXNG. Use for current regulatory guidance, public supplier information, or recent incidents.',
                'parameters'  => [
                    'query' => 'string — the search query',
                ],
            ],
            [
                'name'        => 'get_document',
                'description' => 'Retrieve the full text of a specific document, or a specific section within it.',
                'parameters'  => [
                    'document_name' => 'string',
                    'section_title' => 'string — optional',
                ],
            ],
        ];
    }
}
