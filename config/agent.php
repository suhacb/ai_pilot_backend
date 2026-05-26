<?php

return [
    'max_iterations'        => env('AGENT_MAX_ITERATIONS', 8),
    'default_model'          => env('OLLAMA_GENERATIVE_MODEL', 'gemma4:26b'),
    'default_planning_model' => env('OLLAMA_PLANNING_MODEL',   'gemma4:e4b'),
];
