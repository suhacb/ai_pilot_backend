<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('file_path', 500);
            $table->enum('source_type', ['internal_policy', 'legislation']);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->enum('status', ['pending', 'processing', 'indexed', 'failed'])->default('pending');
            $table->timestamp('ingested_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
