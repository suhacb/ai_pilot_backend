<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OllamaModel extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'name',
        'display_name',
        'role',
        'context_window',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'context_window' => 'integer',
            'is_active'      => 'boolean',
        ];
    }
}
