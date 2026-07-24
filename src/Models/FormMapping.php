<?php

namespace Goldnead\Leadhub\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\Leadhub\Database\Factories\FormMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormMapping extends Model
{
    use HasBrand;

    use HasFactory;

    protected $table = 'leadhub_form_mappings';

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
        'attach_full_submission' => 'boolean',
        'default_tags' => 'array',
        'last_processed_at' => 'datetime',
    ];

    public function isEnabled(): bool
    {
        return (bool) $this->enabled;
    }

    public function hasEmailField(): bool
    {
        return ! empty($this->email_field);
    }

    /**
     * Returns the list of fields that this mapping reads from the submission.
     * Useful for the "Test mapping" feature in the CP.
     */
    public function mappedFields(): array
    {
        return array_filter([
            'email' => $this->email_field,
            'first_name' => $this->first_name_field,
            'last_name' => $this->last_name_field,
            'full_name' => $this->full_name_field,
            'phone' => $this->phone_field,
            'company' => $this->company_field,
            'message' => $this->message_field,
            'consent' => $this->consent_field,
            'tags' => $this->tags_field,
        ]);
    }

    protected static function newFactory(): FormMappingFactory
    {
        return FormMappingFactory::new();
    }
}
