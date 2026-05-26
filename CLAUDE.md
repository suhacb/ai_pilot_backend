# CLAUDE.md — AI Compliance Advisor: Backend

## Project Purpose

This is a **proof-of-concept pilot** built for a potential consulting client in the physical and technical security industry (building management, guarded transport, technical surveillance). The goal is to demonstrate the capabilities of a fully **on-premise AI system** — one where all data, all LLM inference, and all processing remain within the client's own infrastructure. No prompts, no documents, and no query results are ever sent to external cloud services.

The system is a **multi-use-case AI agent** that answers compliance questions, generates incident response plans, and performs supplier risk assessments — all grounded in the client's own internal documentation and relevant legislation (ZInfV-1, Slovenian Information Security Act). The agent reasons step-by-step, plans its approach, executes searches across a local knowledge base, and optionally queries the web via a self-hosted search engine.

This backend is the core of that system. A separate SPA frontend (Vue) connects to it via a REST/SSE API.

---

## Business Context

**Client profile:** A group of companies providing physical security services — guarded premises, armored cash transport, technical surveillance systems. They are subject to ZInfV-1 (Zakon o informacijski varnosti) and associated regulations.

**Three primary use cases, served by one unified agent:**

1. **Compliance Gap Advisor** — User asks a compliance question (e.g., "What are our ZInfV-1 obligations regarding third-party contractors?"). Agent searches internal policies and legislation, identifies what is required vs. what is documented, and returns a structured gap analysis with citations.

2. **Incident Response Planning** — User describes a security incident (e.g., "A security guard on an armored transport route reports being followed"). Agent classifies the incident, identifies applicable internal protocols and legal notification obligations, and produces an actionable response plan with document references.

3. **Supplier Risk Assessment** — User provides a supplier or subcontractor description. Agent checks internal supplier management criteria and ZInfV-1 third-party requirements, optionally searches the web for public information, and returns a structured risk profile.

The agent decides which use case it is addressing based on the incoming prompt — there is no explicit mode switching by the user.

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────┐
│                        Vue SPA                          │
│              (REST calls + SSE stream)                  │
└────────────────────────┬────────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────────┐
│                   Laravel Backend                        │
│                                                         │
│  ┌─────────────────────────────────────────────────┐   │
│  │              Agent Orchestrator                  │   │
│  │         ReAct loop (Reason → Act → Observe)      │   │
│  │                                                  │   │
│  │  Tools:                                          │   │
│  │  • search_semantic   → Qdrant                   │   │
│  │  • search_fulltext   → ZincSearch               │   │
│  │  • search_web        → SearXNG                  │   │
│  │  • get_document      → MySQL + file ref         │   │
│  └──────────────────────┬──────────────────────────┘   │
│                          │                              │
│  ┌───────────┐  ┌────────▼──────┐  ┌────────────────┐  │
│  │  Qdrant   │  │  ZincSearch   │  │     MySQL      │  │
│  │ (vectors) │  │  (BM25 text)  │  │  (structured)  │  │
│  └───────────┘  └───────────────┘  └────────────────┘  │
│                                                         │
│  ┌──────────────────────────────────────────────────┐  │
│  │           Document Ingestion Pipeline             │  │
│  │   Word (.docx) → parse → chunk → embed → store   │  │
│  └──────────────────────────────────────────────────┘  │
│                                                         │
│  ┌──────────────────────────────────────────────────┐  │
│  │                  Ollama (local)                   │  │
│  │   Generative: gemma4:27b / gemma4:4b / qwen      │  │
│  │   Embedding:  mxbai-embed-large                  │  │
│  └──────────────────────────────────────────────────┘  │
│                                                         │
│  ┌──────────────────────────────────────────────────┐  │
│  │                SearXNG (local)                    │  │
│  │         Self-hosted web search proxy              │  │
│  └──────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────┘
```

---

## Technology Stack

| Layer | Technology | Notes |
|---|---|---|
| Language / Framework | PHP 8.3 + Laravel 11 | Existing internal framework preference |
| LLM inference | Ollama (local) | All models run on-device |
| Generative models | `gemma4:27b`, `gemma4:4b`, `qwen2.5:72b`, `qwen2.5:7b` | Selectable per request; small model for planning, large for synthesis |
| Embedding model | `mxbai-embed-large` via Ollama | Multilingual, handles Slovenian well |
| Vector store | Qdrant | Semantic similarity search over document chunks |
| Full-text search | ZincSearch | BM25 keyword search; hybrid search with Qdrant |
| Relational DB | MySQL 8 | Document registry, agent session history, structured metadata |
| Web search | SearXNG (self-hosted) | Privacy-preserving, no external API keys needed |
| Containerization | Docker / Docker Compose | All services run in containers |
| Version control | GitHub | Separate repos for backend and frontend |

---

## Multi-Model Strategy

The system supports multiple LLM models simultaneously, selectable at query time. This is intentional — the demo must show this as a feature. Design considerations:

- **Small/fast model** (`gemma4:4b` or `qwen2.5:7b`): used for intent classification, step planning, and tool call generation in the ReAct loop. Low latency, visible "thinking" steps.
- **Large/capable model** (`gemma4:27b` or `qwen2.5:72b`): used for final answer synthesis when the agent has gathered all relevant context.
- **Model selection** is configurable per session via the API, stored in session context.
- The frontend allows the user to switch models to demonstrate the difference live.

Model names and Ollama endpoint are configured via `.env`.

---

## Agent Architecture: ReAct Loop

The core agent follows the **ReAct (Reasoning + Acting)** pattern. Each iteration of the loop:

1. **Reason** — LLM receives the full conversation context + available tools + previous observations. It returns a JSON object with either a tool call or a final answer.
2. **Act** — Laravel executes the requested tool and captures the result.
3. **Observe** — The tool result is appended to the context as an observation.
4. Loop repeats until the LLM returns `finish` action with a `final_answer`.

**Tool definitions** (passed to LLM as structured schema):

```json
[
  {
    "name": "search_semantic",
    "description": "Search the compliance knowledge base using semantic similarity. Best for conceptual questions, finding related policies, or when the exact wording is unknown.",
    "parameters": {
      "query": "string — the search query",
      "top_k": "integer — number of results (default 5)",
      "filter_source_type": "string — optional: 'internal_policy' | 'legislation'"
    }
  },
  {
    "name": "search_fulltext",
    "description": "Search the compliance knowledge base using keyword matching (BM25). Best for finding specific article numbers, defined terms, or exact phrases.",
    "parameters": {
      "query": "string — keywords or phrase to search",
      "top_k": "integer — number of results (default 5)"
    }
  },
  {
    "name": "search_web",
    "description": "Search the public web via local SearXNG instance. Use for current regulatory guidance, public information about suppliers, or recent security incidents. Does not send data to external LLM services.",
    "parameters": {
      "query": "string — the search query"
    }
  },
  {
    "name": "get_document",
    "description": "Retrieve the full text of a specific document by name, or a specific section by document name and section title.",
    "parameters": {
      "document_name": "string",
      "section_title": "string — optional"
    }
  }
]
```

**Maximum iterations:** 8 (configurable). If not resolved, the agent returns a partial answer with a note.

**Streaming:** Each step of the ReAct loop is streamed to the frontend via **Server-Sent Events (SSE)**. The frontend displays agent reasoning steps in real time before the final answer appears. This is critical for the demo — the client must see the agent thinking, not just wait for a result.

---

## Document Ingestion Pipeline

Source documents are Word `.docx` files stored in a configurable directory. The pipeline is triggered via an Artisan command.

**Processing steps:**

1. **Parse** — Extract clean plain text using `phpoffice/phpword`. Preserve heading structure as section metadata.
2. **Chunk** — Split text into overlapping chunks (~500 tokens, ~50 token overlap) using a recursive character splitter. Each chunk carries: `document_name`, `source_type`, `section_title`, `chunk_index`, `content_hash`.
3. **Embed** — Call Ollama `mxbai-embed-large` to generate a vector for each chunk.
4. **Store:**
   - Qdrant: vector + metadata payload
   - ZincSearch: chunk text + metadata (BM25 index)
   - MySQL `document_chunks` table: all metadata + ingestion status
5. **Idempotent** — keyed on `content_hash`; re-ingestion updates rather than duplicates.

**Source types:**
- `internal_policy` — client's internal IS procedures, BCP plans, supplier management documentation
- `legislation` — ZInfV-1 text, associated regulations and ministerial guidelines

---

## Data Model (MySQL)

### `documents`
Tracks each ingested source file.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | varchar | Original filename without extension |
| file_path | varchar | Absolute path to source file |
| source_type | enum | `internal_policy`, `legislation` |
| chunk_count | int | Total chunks generated |
| status | enum | `pending`, `processing`, `indexed`, `failed` |
| ingested_at | timestamp | |

### `document_chunks`
One row per chunk, mirrors what is stored in Qdrant and ZincSearch.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| document_id | bigint FK | |
| qdrant_id | uuid | ID used in Qdrant collection |
| chunk_index | int | Position within document |
| section_title | varchar | Heading context |
| content | text | Raw chunk text |
| content_hash | char(64) | SHA-256 for idempotency |
| token_count | int | Approximate token count |

### `agent_sessions`
Tracks each user interaction session.

| Column | Type | Notes |
|---|---|---|
| id | uuid PK | |
| model_generative | varchar | Active generative model name |
| model_embedding | varchar | Active embedding model name |
| use_case_detected | enum | `compliance`, `incident`, `supplier`, `unknown` |
| created_at | timestamp | |

### `agent_steps`
One row per ReAct iteration within a session.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| session_id | uuid FK | |
| step_index | int | |
| reasoning | text | LLM's reasoning text |
| action_tool | varchar | Tool called (`search_semantic`, etc.) |
| action_params | json | Parameters passed to the tool |
| observation | text | Tool result |
| created_at | timestamp | |

---

## API Endpoints

### `POST /api/sessions`
Create a new agent session. Returns `session_id`.

**Body:**
```json
{
  "model": "gemma4:27b",
  "stream": true
}
```

### `POST /api/sessions/{id}/query`
Submit a query to the agent. If `stream: true` was set on the session, responds with `Content-Type: text/event-stream`.

**SSE event types streamed back:**
- `step` — one ReAct iteration (reasoning + tool call + observation)
- `answer` — final synthesized answer
- `error` — if something goes wrong

**Body:**
```json
{
  "prompt": "What are our obligations under ZInfV-1 regarding incident notification?"
}
```

### `GET /api/sessions/{id}/steps`
Retrieve all recorded steps for a session (for replay / audit).

### `GET /api/documents`
List all ingested documents with status.

### `POST /api/documents/ingest`
Trigger ingestion pipeline (admin/demo use). Accepts `source_type` filter.

### `GET /api/models`
List available Ollama models with their roles (generative / embedding).

---

## Configuration (`.env`)

```dotenv
# Ollama
OLLAMA_URL=http://ollama:11434
OLLAMA_GENERATIVE_MODEL=gemma4:27b
OLLAMA_EMBEDDING_MODEL=mxbai-embed-large

# Qdrant
QDRANT_URL=http://qdrant:6333
QDRANT_COLLECTION=compliance_docs

# ZincSearch
ZINCSEARCH_URL=http://zincsearch:4080
ZINCSEARCH_INDEX=compliance_docs
ZINCSEARCH_USER=admin
ZINCSEARCH_PASSWORD=secret

# SearXNG
SEARXNG_URL=http://searxng:8080

# Agent
AGENT_MAX_ITERATIONS=8
AGENT_STREAM=true
```

---

## Development Priorities

Build in this order — each phase is a working, testable system before the next begins:

**Phase 1 — Data foundation**
Document ingestion pipeline: Word → parse → chunk → embed → Qdrant + ZincSearch + MySQL. Verify with direct vector queries before proceeding.

**Phase 2 — Agent core**
ReAct loop with all four tools wired up. Test with raw HTTP (curl/Postman) before adding the SSE layer. Verify that multi-step reasoning produces coherent plans.

**Phase 3 — API + streaming**
SSE endpoint. Ensure each step is emitted as it happens, not buffered. Test with a simple HTML client before connecting the Vue frontend.

**Phase 4 — Multi-model support**
Model switching per session. Test latency difference between small and large models on the same query.

**Phase 5 — Demo scenarios**
Prepare three scripted prompts (compliance, incident, supplier) with known good responses. Tune system prompt and chunking strategy against real documents until output quality is demo-ready.

---

## Key Constraints

- **No external LLM API calls.** All inference through local Ollama. Enforce this at the service layer — no OpenAI/Anthropic/Google SDK imports.
- **No external data exfiltration.** SearXNG is self-hosted; it proxies web search without identifying the client.
- **Slovenian language.** Documents and queries are primarily in Slovenian. The embedding model and generative models must handle this well. Do not assume English-only behavior.
- **Demo latency.** On M5 Pro with 24GB RAM, `gemma4:27b` at 4-bit quantization should achieve acceptable token generation speed. If latency is problematic, fall back to `gemma4:4b` for planning steps and reserve the large model for final synthesis only.
- **3-day build window.** Avoid over-engineering. Prefer explicit, readable service classes over clever abstractions. The code will be shown to a client — it should be explainable.