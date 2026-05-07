<?php

namespace Goldnead\Leadhub\Database\Factories;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Note;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class NoteFactory extends Factory
{
    protected $model = Note::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'contact_id' => Contact::factory(),
            'user_id' => null,
            'body' => $this->faker->paragraph(),
        ];
    }
}
