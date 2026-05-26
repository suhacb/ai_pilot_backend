<?php

namespace App\Console\Commands;

use App\Jobs\IngestDocumentJob;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Ingestion\QdrantStore;
use App\Services\Ingestion\ZincSearchStore;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class ReIngestCommand extends Command
{
    protected $signature = 'documents:reingest
                            {filename? : Basename of the file to re-ingest (e.g. policy.docx). Omit to re-ingest all documents with 0 chunks.}';

    protected $description = 'Wipe and re-queue document(s) for ingestion. Without argument: queues all documents with 0 chunks.';

    public function __construct(
        private readonly QdrantStore $qdrant,
        private readonly ZincSearchStore $zinc,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $filename = $this->argument('filename');

        if ($filename) {
            $documents = $this->resolveByFilename($filename);
            if ($documents === null) {
                return self::FAILURE;
            }
        } else {
            $documents = $this->resolveZeroChunk();
        }

        if ($documents->isEmpty()) {
            $this->line('No documents with 0 chunks found. Nothing to re-ingest.');
            return self::SUCCESS;
        }

        foreach ($documents as $document) {
            $this->reingest($document);
        }

        $count = $documents->count();
        $this->info("$count document(s) queued for re-ingestion.");

        return self::SUCCESS;
    }

    /** Returns null to signal a lookup failure. */
    private function resolveByFilename(string $filename): ?Collection
    {
        $document = Document::where('file_path', 'like', '%/' . $filename)
            ->orWhere('file_path', $filename)
            ->first();

        if (! $document) {
            $this->error("No document record found for filename: $filename");
            return null;
        }

        return Document::where('id', $document->id)->get();
    }

    private function resolveZeroChunk(): Collection
    {
        return Document::where('chunk_count', 0)->get();
    }

    private function reingest(Document $document): void
    {
        $basename = basename($document->file_path);

        $this->line("  Wiping: {$basename}");

        $this->qdrant->deleteByDocument($document->name);
        $this->zinc->deleteByDocument($document->name);
        DocumentChunk::where('document_id', $document->id)->delete();

        $document->update(['status' => 'pending', 'chunk_count' => 0, 'ingested_at' => null]);

        IngestDocumentJob::dispatch($basename, $document->source_type);
        $this->line("  Queued [{$document->source_type}]: {$basename}");
    }
}
