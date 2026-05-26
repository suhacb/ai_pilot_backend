<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Ingestion\DocxParser;
use App\Services\Ingestion\HtmlParser;
use App\Services\Ingestion\OllamaEmbedder;
use App\Services\Ingestion\QdrantStore;
use App\Services\Ingestion\TextChunker;
use App\Services\Ingestion\ZincSearchStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class IngestDocumentCommand extends Command
{
    protected $signature = 'documents:ingest
                            {filename : Name of the .docx file inside the configured documents directory}
                            {--source-type=internal_policy : internal_policy|legislation}';

    protected $description = 'Parse, chunk, embed and store a .docx document from the documents storage path';

    public function __construct(
        private readonly DocxParser $docxParser,
        private readonly HtmlParser $htmlParser,
        private readonly TextChunker $chunker,
        private readonly OllamaEmbedder $embedder,
        private readonly QdrantStore $qdrant,
        private readonly ZincSearchStore $zinc,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $filename   = $this->argument('filename');
        $sourceType = $this->option('source-type');

        if (! in_array($sourceType, ['internal_policy', 'legislation'], true)) {
            $this->error("Invalid source-type '$sourceType'. Use: internal_policy or legislation.");
            return self::FAILURE;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! in_array($extension, ['docx', 'html', 'htm'], true)) {
            $this->error("File must have a .docx or .html extension: $filename");
            return self::FAILURE;
        }

        $disk         = Storage::disk('local');
        $relativePath = config('services.documents.path') . '/' . $filename;

        if (! $disk->exists($relativePath)) {
            $this->error("File not found in document storage: $relativePath");
            return self::FAILURE;
        }

        // phpoffice/phpword requires an absolute filesystem path
        $absolutePath = $disk->path($relativePath);
        $name         = pathinfo($filename, PATHINFO_FILENAME);

        $document = Document::updateOrCreate(
            ['file_path' => $relativePath],
            [
                'name'        => $name,
                'source_type' => $sourceType,
                'status'      => 'processing',
                'chunk_count' => 0,
                'ingested_at' => null,
            ]
        );

        Log::info("Ingestion started", ['document' => $name, 'source_type' => $sourceType]);

        try {
            $sections = $extension === 'docx'
                ? $this->docxParser->parse($absolutePath)
                : $this->htmlParser->parse($absolutePath);
            $chunks   = $this->chunker->chunk($sections, $name, $sourceType);

            Log::info("Parsed and chunked", ['document' => $name, 'chunks' => count($chunks), 'sections' => count($sections)]);

            if (empty($chunks)) {
                $this->warn("No chunks produced — document may be empty.");
                Log::warning("No chunks produced", ['document' => $name]);
                $document->update(['status' => 'indexed', 'ingested_at' => now()]);
                return self::SUCCESS;
            }

            $firstVector = $this->embedder->embed($chunks[0]['content']);
            $this->qdrant->ensureCollection(count($firstVector));

            Log::info("Qdrant collection ready", ['document' => $name, 'dimensions' => count($firstVector)]);

            $total   = count($chunks);
            $indexed = 0;

            foreach ($chunks as $i => $chunk) {
                if (DocumentChunk::where('content_hash', $chunk['content_hash'])->exists()) {
                    $this->line("Chunk " . ($i + 1) . "/$total — skipped (already indexed)");
                    continue;
                }

                $vector  = $i === 0 ? $firstVector : $this->embedder->embed($chunk['content']);
                $pointId = Str::uuid()->toString();

                $this->qdrant->upsert($pointId, $vector, [
                    'document_name' => $chunk['document_name'],
                    'source_type'   => $chunk['source_type'],
                    'section_title' => $chunk['section_title'],
                    'chunk_index'   => $chunk['chunk_index'],
                    'content'       => $chunk['content'],
                    'content_hash'  => $chunk['content_hash'],
                ]);

                $this->zinc->upsert($pointId, [
                    'document_name' => $chunk['document_name'],
                    'source_type'   => $chunk['source_type'],
                    'section_title' => $chunk['section_title'],
                    'chunk_index'   => $chunk['chunk_index'],
                    'content'       => $chunk['content'],
                    'content_hash'  => $chunk['content_hash'],
                ]);

                DocumentChunk::create([
                    'document_id'   => $document->id,
                    'qdrant_id'     => $pointId,
                    'chunk_index'   => $chunk['chunk_index'],
                    'section_title' => $chunk['section_title'],
                    'content'       => $chunk['content'],
                    'content_hash'  => $chunk['content_hash'],
                    'token_count'   => $chunk['token_count'],
                ]);

                $indexed++;
                $this->line("Chunk " . ($i + 1) . "/$total — '{$chunk['section_title']}'");

                if ($indexed % 10 === 0) {
                    Log::info("Indexing progress", ['document' => $name, 'indexed' => $indexed, 'total' => $total]);
                }
            }

            $document->update([
                'status'      => 'indexed',
                'chunk_count' => $indexed,
                'ingested_at' => now(),
            ]);

            $this->info("Done. $indexed chunks indexed for '$name'.");
            Log::info("Ingestion complete", ['document' => $name, 'indexed' => $indexed, 'total' => $total]);

        } catch (\Throwable $e) {
            $document->update(['status' => 'failed']);
            $this->error($e->getMessage());
            Log::error("Ingestion failed", [
                'document'  => $name,
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
