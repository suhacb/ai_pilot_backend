<?php

namespace Tests;

use App\Models\OllamaModel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function createOllamaModel(string $name, string $role = 'generative', bool $isActive = true): OllamaModel
    {
        return OllamaModel::create([
            'name'           => $name,
            'display_name'   => $name,
            'role'           => $role,
            'context_window' => 8192,
            'is_active'      => $isActive,
        ]);
    }
}
