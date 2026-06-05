<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_steps', function (Blueprint $table) {
            $table->longText('raw_llm_response')->nullable()->after('observation');
            $table->unsignedInteger('context_length')->nullable()->after('raw_llm_response')
                ->comment('Character length of the planning context sent to the model at this step');
        });
    }

    public function down(): void
    {
        Schema::table('agent_steps', function (Blueprint $table) {
            $table->dropColumn(['raw_llm_response', 'context_length']);
        });
    }
};
