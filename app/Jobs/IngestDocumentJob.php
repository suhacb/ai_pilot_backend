<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class IngestDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 3;

    public function __construct(
        public readonly string $filename,
        public readonly string $sourceType,
    ) {}

    public function handle(): void
    {
        Log::info("Ingestion job started", ['filename' => $this->filename, 'source_type' => $this->sourceType, 'attempt' => $this->attempts()]);

        $exitCode = Artisan::call('documents:ingest', [
            'filename'      => $this->filename,
            '--source-type' => $this->sourceType,
        ]);

        if ($exitCode !== 0) {
            $output = trim(Artisan::output());
            Log::error("Ingestion job failed", ['filename' => $this->filename, 'output' => $output]);
            throw new \RuntimeException("Ingestion failed for '{$this->filename}': {$output}");
        }

        Log::info("Ingestion job completed", ['filename' => $this->filename]);
    }
}
