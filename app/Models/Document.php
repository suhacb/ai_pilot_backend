<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'file_path',
        'source_type',
        'chunk_count',
        'status',
        'ingested_at',
    ];

    protected function casts(): array
    {
        return [
            'ingested_at' => 'datetime',
        ];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }
}
