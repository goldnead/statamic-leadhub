<?php

namespace Goldnead\Leadhub\Contracts\Repositories;

use Goldnead\Leadhub\Models\FormMapping;
use Illuminate\Support\Collection;

interface FormMappingRepository
{
    public function findByHandle(string $formHandle): ?FormMapping;

    public function firstOrCreate(string $formHandle, array $defaults = []): FormMapping;

    public function update(FormMapping $mapping, array $attributes): FormMapping;

    public function delete(FormMapping $mapping): void;

    public function all(): Collection;

    /** Returns true if at least one mapping is enabled. */
    public function anyEnabled(): bool;

    /** Touch the last_processed_at timestamp. */
    public function markProcessed(FormMapping $mapping): void;

    /** Available source_form values (for the contact-list filter). */
    public function enabledHandles(): array;
}
