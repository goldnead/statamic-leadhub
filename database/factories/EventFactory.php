<?php

namespace Goldnead\Leadhub\Database\Factories;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'contact_id' => Contact::factory(),
            'type' => Event::TYPE_SUBMISSION_RECEIVED,
            'actor_type' => 'system',
            'actor_id' => null,
            'summary' => $this->faker->sentence(),
            'payload' => [],
        ];
    }

    public function ofType(string $type): self
    {
        return $this->state(fn () => ['type' => $type]);
    }
}
