<?php

namespace App\Console\Commands;

use App\Models\OllamaModel;
use Illuminate\Console\Command;

class ModelRegisterCommand extends Command
{
    protected $signature = 'model:register
                            {name : Ollama model identifier, e.g. gemma4:26b}
                            {--context-window= : Context window size in tokens}
                            {--role=generative : Model role: generative or embedding}
                            {--display-name= : Human-readable name (defaults to model identifier)}
                            {--force : Update existing registration}';

    protected $description = 'Register an Ollama model with its context window and role';

    public function handle(): int
    {
        $name        = $this->argument('name');
        $role        = $this->option('role');
        $displayName = $this->option('display-name') ?: $name;

        if (!in_array($role, ['generative', 'embedding'])) {
            $this->error("Role must be 'generative' or 'embedding'.");
            return self::FAILURE;
        }

        $contextWindow = $this->option('context-window');
        if ($contextWindow === null) {
            $contextWindow = $this->ask('Context window size in tokens?');
        }
        $contextWindow = (int) $contextWindow;
        if ($contextWindow <= 0) {
            $this->error('Context window must be a positive integer.');
            return self::FAILURE;
        }

        $existing = OllamaModel::where('name', $name)->first();

        if ($existing && !$this->option('force')) {
            $this->warn("Model '{$name}' is already registered (context_window={$existing->context_window}, role={$existing->role}).");
            $this->line("Use --force to update it.");
            return self::FAILURE;
        }

        OllamaModel::updateOrCreate(
            ['name' => $name],
            [
                'display_name'   => $displayName,
                'role'           => $role,
                'context_window' => $contextWindow,
                'is_active'      => true,
            ]
        );

        $verb = $existing ? 'Updated' : 'Registered';
        $this->info("{$verb}: {$name} | role={$role} | context_window={$contextWindow} | display_name={$displayName}");

        return self::SUCCESS;
    }
}
