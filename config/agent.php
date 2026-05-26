<?php

return [
    'max_iterations'        => env('AGENT_MAX_ITERATIONS', 8),
    'default_model'         => env('OLLAMA_GENERATIVE_MODEL', 'qwen2.5:32b'),
    'default_planning_model' => env('OLLAMA_PLANNING_MODEL', 'qwen2.5:7b'),
];
