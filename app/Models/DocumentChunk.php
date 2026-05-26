<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChunk extends Model
{
    protected $fillable = [
        'document_id',
        'qdrant_id',
        'chunk_index',
        'section_title',
        'content',
        'content_hash',
        'token_count',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
