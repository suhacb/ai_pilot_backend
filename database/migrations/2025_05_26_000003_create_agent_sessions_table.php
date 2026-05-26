<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('model_generative', 100);
            $table->string('model_embedding', 100);
            $table->enum('use_case_detected', ['compliance', 'incident', 'supplier', 'unknown'])
                  ->default('unknown');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_sessions');
    }
};
