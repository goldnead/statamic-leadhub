<?php

namespace Goldnead\Leadhub\Database\Factories;

use Goldnead\Leadhub\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        $email = $this->faker->unique()->safeEmail();
        $first = $this->faker->firstName();
        $last = $this->faker->lastName();

        return [
            'uuid' => (string) Str::uuid(),
            'email' => $email,
            // Deliberately not set here. `definition()` cannot see an override,
            // so a hard-coded value made `create(['email' => 'wer@example.com'])`
            // keep the faker's normalized address — a row that displays one
            // address and is findable only under another, so every lookup by
            // email missed and the failure read as "it cannot find a contact
            // that plainly exists". Left empty, the model's `creating` hook
            // derives it from whatever email survives, and a caller that sets
            // `email_normalized` on purpose still wins because the hook only
            // fills an empty one.
            'first_name' => $first,
            'last_name' => $last,
            'full_name' => $first.' '.$last,
            'phone' => $this->faker->optional()->phoneNumber(),
            'company' => $this->faker->optional()->company(),
            'status' => 'new',
            'source' => 'website',
            'source_form' => 'contact',
            'consent' => false,
        ];
    }

    public function archived(): self
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }

    public function withStatus(string $status): self
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
