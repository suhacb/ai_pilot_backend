<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class DocumentController extends Controller
{
    /**
     * GET /api/documents
     * List all ingested documents.
     */
    public function index(): JsonResponse
    {
        return response()->json(
            Document::orderByDesc('created_at')->get()
        );
    }

    /**
     * POST /api/documents/ingest
     * Trigger the ingestion pipeline for a file in the document storage path.
     */
    public function ingest(Request $request): JsonResponse
    {
        $request->validate([
            'filename'    => ['required', 'string', 'regex:/\.(docx|html|htm)$/i'],
            'source_type' => ['required', 'in:internal_policy,legislation'],
        ]);

        Artisan::call('documents:ingest', [
            'filename'      => $request->input('filename'),
            '--source-type' => $request->input('source_type'),
        ]);

        return response()->json(['message' => 'Ingestion started.']);
    }
}
