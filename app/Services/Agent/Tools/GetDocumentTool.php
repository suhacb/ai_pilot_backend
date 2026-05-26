<?php

namespace App\Services\Agent\Tools;

use App\Models\DocumentChunk;

class GetDocumentTool
{
    /**
     * Retrieve document chunks from MySQL by document name, optionally filtered by section.
     *
     * Params:
     *   document_name  string  — required
     *   section_title  string  — optional
     */
    public function execute(array $params): string
    {
        $documentName = $params['document_name'];
        $sectionTitle = $params['section_title'] ?? null;

        $query = DocumentChunk::whereHas(
            'document',
            fn($q) => $q->where('name', $documentName)
        );

        if ($sectionTitle !== null) {
            $query->where('section_title', $sectionTitle);
        }

        $chunks = $query->orderBy('chunk_index')->get();

        if ($chunks->isEmpty()) {
            return "Document '$documentName' not found in the knowledge base.";
        }

        $lines   = ["Document: $documentName\n"];
        $current = null;

        foreach ($chunks as $chunk) {
            if ($chunk->section_title !== $current) {
                $current = $chunk->section_title;
                if ($current) {
                    $lines[] = "\nSection: $current";
                }
            }
            $lines[] = $chunk->content;
        }

        return implode("\n", $lines);
    }
}
