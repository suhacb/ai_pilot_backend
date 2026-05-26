<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentStep extends Model
{
    protected $fillable = [
        'session_id',
        'step_index',
        'reasoning',
        'action_tool',
        'action_params',
        'observation',
    ];

    protected function casts(): array
    {
        return [
            'action_params' => 'array',
        ];
    }
}
