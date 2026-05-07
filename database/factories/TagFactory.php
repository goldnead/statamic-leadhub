<?php

namespace Goldnead\Leadhub\Database\Factories;

use Goldnead\Leadhub\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TagFactory extends Factory
{
    protected $model = Tag::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->word();

        return [
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'color' => $this->faker->optional()->hexColor(),
        ];
    }
}
