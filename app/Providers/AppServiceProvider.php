<?php

namespace App\Providers;

use App\Services\Ingestion\OllamaEmbedder;
use App\Services\Ingestion\QdrantStore;
use App\Services\Ingestion\ZincSearchStore;
use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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
    }

    public function boot(): void {}
}
