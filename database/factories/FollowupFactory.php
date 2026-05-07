<?php

namespace Goldnead\Leadhub\Database\Factories;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Followup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class FollowupFactory extends Factory
{
    protected $model = Followup::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'contact_id' => Contact::factory(),
            'due_at' => now()->addDays(3),
            'note' => $this->faker->optional()->sentence(),
            'completed_at' => null,
            'created_by' => null,
            'completed_by' => null,
        ];
    }

    public function overdue(): self
    {
        return $this->state(fn () => ['due_at' => now()->subDays(2)]);
    }

    public function dueToday(): self
    {
        return $this->state(fn () => ['due_at' => now()]);
    }

    public function completed(): self
    {
        return $this->state(fn () => ['completed_at' => now()]);
    }
}
