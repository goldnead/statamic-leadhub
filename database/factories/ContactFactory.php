<?php

namespace Goldnead\Leadhub\Database\Factories;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\EmailNormalizer;
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
            'email_normalized' => EmailNormalizer::normalize($email),
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
