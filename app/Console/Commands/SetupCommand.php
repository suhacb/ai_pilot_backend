<?php

namespace App\Console\Commands;

use App\Jobs\IngestDocumentJob;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Ingestion\QdrantStore;
use App\Services\Ingestion\ZincSearchStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SetupCommand extends Command
{
    protected $signature   = 'app:setup';
    protected $description = 'Queue ingestion of all compliance documents into the knowledge base';

    /**
     * Documents to ingest.
     * Filenames must match exactly what is on disk (including any trailing spaces).
     */
    private const DOCUMENTS = [
        // ── Legislation ──────────────────────────────────────────────────────
        ['filename' => 'Uradni_list_L_333_2022.html', 'source_type' => 'legislation'],
        ['filename' => 'ZAKO8934_NPB0.html',           'source_type' => 'legislation'],

        // ── Internal policy ──────────────────────────────────────────────────
        ['filename' => 'ANALIZA STANJA SUVI in SUNP.docx',                                              'source_type' => 'internal_policy'],
        ['filename' => 'ANALIZA VPLIVOV NA POSLOVANJE (BIA).docx',                                      'source_type' => 'internal_policy'],
        ['filename' => 'GAP ANALIZA SKLADNOSTI  .docx',                                                  'source_type' => 'internal_policy'],
        ['filename' => 'IZJAVA O PRIMERNOSTI (Statement of Applicability – SoA).docx',                  'source_type' => 'internal_policy'],
        ['filename' => 'KROVNA VARNOSTNA POLITIKA.docx',                                                 'source_type' => 'internal_policy'],
        ['filename' => 'MODEL UPRAVLJANJA INFORMACIJSKE .docx',                                          'source_type' => 'internal_policy'],
        ['filename' => 'NAČRT NEPREKINJENEGA POSLOVANJA (BCP).docx',                                    'source_type' => 'internal_policy'],
        ['filename' => 'NAČRT OBNOVITVE PO IZREDNEM DOGODKU (DRP) .docx',                              'source_type' => 'internal_policy'],
        ['filename' => 'NAČRT RAZVOJA INFORMACIJSKE VARNOSTI 2025–2027 .docx',                         'source_type' => 'internal_policy'],
        ['filename' => 'N_Politika upravljanja z dobavitelji.docx',                                      'source_type' => 'internal_policy'],
        ['filename' => 'POLITIKA DODELJEVANJA IN NADZORA DOSTOPOV.docx',                                'source_type' => 'internal_policy'],
        ['filename' => 'POLITIKA FIZIČNE VARNOSTI.docx',                                                'source_type' => 'internal_policy'],
        ['filename' => 'POLITIKA INFORMACIJSKE VARNOSTI.docx',                                          'source_type' => 'internal_policy'],
        ['filename' => 'POLITIKA NADZORA SPREMEMB.docx',                                                'source_type' => 'internal_policy'],
        ['filename' => 'POLITIKA UPORABE GESEL.docx',                                                   'source_type' => 'internal_policy'],
        ['filename' => 'POLITIKA UPORABE KRIPTOGRAFSKIH KONTROL.docx',                                  'source_type' => 'internal_policy'],
        ['filename' => 'POLITIKA UPRAVLJANJA INCIDENTOV.docx',                                          'source_type' => 'internal_policy'],
        ['filename' => 'POLITIKA UPRAVLJANJA Z DOBAVITELJI.docx',                                        'source_type' => 'internal_policy'],
        ['filename' => 'POLITIKA VARNOSTNEGA KOPIRANJA.docx',                                           'source_type' => 'internal_policy'],
        ['filename' => 'POLITIKA VIDEO NADZORA.docx',                                                    'source_type' => 'internal_policy'],
        ['filename' => 'POVEZAVA ZAHTEV, POLITIK IN DOKAZOV.docx',                                      'source_type' => 'internal_policy'],
        ['filename' => 'POVZETEK ZA VODSTVO – INFORMACIJSKA VARNOST IN NEPREKINJENO POSLOVANJE.docx',  'source_type' => 'internal_policy'],
        ['filename' => 'Single point of failure.docx',                                                   'source_type' => 'internal_policy'],
        ['filename' => 'Soglasje JN Ukrepi - SIEM Cynet VM SB MS.docx',                                'source_type' => 'internal_policy'],
        ['filename' => 'STRUKTURA KONČNEGA PAKETA DOKUMENTACIJE .docx',                                'source_type' => 'internal_policy'],
        ['filename' => 'TRACEABILITY MATRIX – TVEGANJA, KONTROLE IN DOKUMENTI.docx',                   'source_type' => 'internal_policy'],
    ];

    public function __construct(
        private readonly QdrantStore $qdrant,
        private readonly ZincSearchStore $zinc,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->line('Wiping Qdrant collection...');
        $this->qdrant->dropCollection();
        $this->line('Wiping ZincSearch index...');
        $this->zinc->dropIndex();
        $this->line('Wiping document records from MySQL...');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DocumentChunk::truncate();
        Document::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->newLine();

        $docsPath   = config('services.documents.path');
        $disk       = Storage::disk('local');
        $dispatched = 0;
        $skipped    = 0;

        foreach (self::DOCUMENTS as $doc) {
            $relativePath = $docsPath . '/' . $doc['filename'];

            if (! $disk->exists($relativePath)) {
                $this->warn("Not found — skipping: {$doc['filename']}");
                $skipped++;
                continue;
            }

            IngestDocumentJob::dispatch($doc['filename'], $doc['source_type']);
            $this->line("  Queued [{$doc['source_type']}]: {$doc['filename']}");
            $dispatched++;
        }

        $this->newLine();
        $this->info("$dispatched jobs queued, $skipped skipped.");
        $this->line("Run the worker to process them:");
        $this->line("  php artisan queue:work --timeout=600");

        return self::SUCCESS;
    }
}
