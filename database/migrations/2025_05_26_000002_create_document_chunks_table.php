<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->char('qdrant_id', 36);
            $table->unsignedInteger('chunk_index');
            $table->string('section_title')->nullable();
            $table->text('content');
            $table->char('content_hash', 64);
            $table->unsignedInteger('token_count');
            $table->timestamps();

            $table->index('content_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
