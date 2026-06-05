<?php

namespace App\Http\Controllers;

use App\Models\OllamaModel;
use Illuminate\Http\JsonResponse;

class ModelController extends Controller
{
    /**
     * GET /api/models
     * List all non-deleted registered models with their status.
     * Returns both active and inactive models so the full registry is visible.
     */
    public function index(): JsonResponse
    {
        $models = OllamaModel::orderBy('role')
            ->orderBy('display_name')
            ->get(['id', 'name', 'display_name', 'role', 'context_window', 'is_active']);

        return response()->json($models);
    }

    /**
     * DELETE /api/models/{id}
     * Unregister a model by soft-deleting it.
     * Active sessions using this model are unaffected until they complete.
     */
    public function destroy(int $id): JsonResponse
    {
        $model = OllamaModel::findOrFail($id);
        $model->delete();
        return response()->json(['deleted' => true]);
    }
}
