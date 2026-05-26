<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_steps', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id');
            $table->foreign('session_id')->references('id')->on('agent_sessions')->cascadeOnDelete();
            $table->unsignedInteger('step_index');
            $table->text('reasoning')->nullable();
            $table->string('action_tool', 100)->nullable();
            $table->json('action_params')->nullable();
            $table->text('observation')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_steps');
    }
};
