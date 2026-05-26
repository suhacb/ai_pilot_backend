<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AgentSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'model_generative',
        'model_embedding',
        'use_case_detected',
    ];
}
