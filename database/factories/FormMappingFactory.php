<?php

namespace Goldnead\Leadhub\Database\Factories;

use Goldnead\Leadhub\Models\FormMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

class FormMappingFactory extends Factory
{
    protected $model = FormMapping::class;

    public function definition(): array
    {
        return [
            'form_handle' => 'contact_'.$this->faker->unique()->numberBetween(1, 999999),
            'enabled' => true,
            'email_field' => 'email',
            'first_name_field' => 'first_name',
            'last_name_field' => 'last_name',
            'full_name_field' => null,
            'phone_field' => 'phone',
            'company_field' => 'company',
            'message_field' => 'message',
            'consent_field' => null,
            'tags_field' => null,
            'default_status' => 'new',
            'default_source' => 'website',
            'default_tags' => null,
            'attach_full_submission' => true,
        ];
    }

    public function disabled(): self
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
