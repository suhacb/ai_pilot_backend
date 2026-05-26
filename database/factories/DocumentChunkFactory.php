<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentChunk>
 */
class DocumentChunkFactory extends Factory
{
    protected $model = DocumentChunk::class;

    public function definition(): array
    {
        $content = $this->faker->paragraph();

        return [
            'document_id'   => Document::factory(),
            'qdrant_id'     => Str::uuid()->toString(),
            'chunk_index'   => $this->faker->numberBetween(0, 50),
            'section_title' => $this->faker->sentence(4),
            'content'       => $content,
            'content_hash'  => hash('sha256', $content),
            'token_count'   => $this->faker->numberBetween(50, 400),
        ];
    }
}
