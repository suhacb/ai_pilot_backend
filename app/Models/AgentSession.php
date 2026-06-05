<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AgentSession extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'model_generative',
        'model_planning',
        'model_embedding',
        'model_locked',
        'use_case_detected',
        'conversation_history',
    ];

    protected function casts(): array
    {
        return [
            'model_locked'        => 'boolean',
            'conversation_history' => 'array',
        ];
    }
}
