<?php

namespace Database\Factories;

use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        $name = $this->faker->words(3, true);

        return [
            'name'        => $name,
            'file_path'   => 'docs/' . str_replace(' ', '_', $name) . '.docx',
            'source_type' => $this->faker->randomElement(['internal_policy', 'legislation']),
            'chunk_count' => 0,
            'status'      => 'pending',
            'ingested_at' => null,
        ];
    }
}
