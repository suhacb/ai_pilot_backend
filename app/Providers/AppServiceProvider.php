<?php

namespace App\Providers;

use App\Services\Agent\AgentOrchestrator;
use App\Services\Agent\OllamaClient;
use App\Services\Agent\QueryExpander;
use App\Services\Agent\RagRetriever;
use App\Services\Agent\ResultReranker;
use App\Services\Agent\Tools\FulltextSearchTool;
use App\Services\Agent\Tools\GetDocumentTool;
use App\Services\Agent\Tools\SemanticSearchTool;
use App\Services\Agent\Tools\WebSearchTool;
use App\Services\Ingestion\OllamaEmbedder;
use App\Services\Ingestion\QdrantStore;
use App\Services\Ingestion\ZincSearchStore;
use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ── Ingestion ────────────────────────────────────────────────────────

        $this->app->singleton(OllamaEmbedder::class, fn() => new OllamaEmbedder(
            new Client(['timeout' => 120.0]),
            config('services.ollama.url'),
            config('services.ollama.embedding_model'),
        ));

        $this->app->singleton(QdrantStore::class, fn() => new QdrantStore(
            new Client(['timeout' => 30.0]),
            config('services.qdrant.url'),
            config('services.qdrant.collection'),
        ));

        $this->app->singleton(ZincSearchStore::class, fn() => new ZincSearchStore(
            new Client(['timeout' => 30.0]),
            config('services.zincsearch.url'),
            config('services.zincsearch.index'),
            config('services.zincsearch.user'),
            config('services.zincsearch.password'),
        ));

        // ── Agent ────────────────────────────────────────────────────────────

        $this->app->singleton(OllamaClient::class, fn() => new OllamaClient(
            new Client(['timeout' => 600.0]),
            config('services.ollama.url'),
        ));

        $this->app->singleton(SemanticSearchTool::class, fn($app) => new SemanticSearchTool(
            $app->make(OllamaEmbedder::class),
            new Client(['timeout' => 30.0]),
            config('services.qdrant.url'),
            config('services.qdrant.collection'),
        ));

        $this->app->singleton(FulltextSearchTool::class, fn() => new FulltextSearchTool(
            new Client(['timeout' => 30.0]),
            config('services.zincsearch.url'),
            config('services.zincsearch.index'),
            config('services.zincsearch.user'),
            config('services.zincsearch.password'),
        ));

        $this->app->singleton(WebSearchTool::class, fn() => new WebSearchTool(
            new Client(['timeout' => 30.0]),
            config('services.searxng.url'),
        ));

        $this->app->singleton(QueryExpander::class, fn($app) => new QueryExpander(
            $app->make(OllamaClient::class),
        ));

        $this->app->singleton(ResultReranker::class, fn($app) => new ResultReranker(
            $app->make(OllamaClient::class),
        ));

        $this->app->singleton(RagRetriever::class, fn($app) => new RagRetriever(
            $app->make(SemanticSearchTool::class),
            $app->make(FulltextSearchTool::class),
            $app->make(QueryExpander::class),
            $app->make(ResultReranker::class),
        ));

        $this->app->singleton(AgentOrchestrator::class, fn($app) => new AgentOrchestrator(
            $app->make(OllamaClient::class),
            $app->make(RagRetriever::class),
            $app->make(WebSearchTool::class),
            $app->make(GetDocumentTool::class),
            config('agent.max_iterations'),
        ));
    }

    public function boot(): void {}
}
