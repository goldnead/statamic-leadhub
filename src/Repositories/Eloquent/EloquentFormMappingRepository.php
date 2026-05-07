<?php

namespace Goldnead\Leadhub\Repositories\Eloquent;

use Goldnead\Leadhub\Contracts\Repositories\FormMappingRepository;
use Goldnead\Leadhub\Models\FormMapping;
use Illuminate\Support\Collection;

class EloquentFormMappingRepository implements FormMappingRepository
{
    public function findByHandle(string $formHandle): ?FormMapping
    {
        return FormMapping::query()->where('form_handle', $formHandle)->first();
    }

    public function firstOrCreate(string $formHandle, array $defaults = []): FormMapping
    {
        return FormMapping::query()->firstOrCreate(
            ['form_handle' => $formHandle],
            array_merge(['enabled' => false], $defaults),
        );
    }

    public function update(FormMapping $mapping, array $attributes): FormMapping
    {
        $mapping->fill($attributes);
        $mapping->save();

        return $mapping;
    }

    public function delete(FormMapping $mapping): void
    {
        $mapping->delete();
    }

    public function all(): Collection
    {
        return FormMapping::query()->orderBy('form_handle')->get();
    }

    public function anyEnabled(): bool
    {
        return FormMapping::query()->where('enabled', true)->exists();
    }

    public function markProcessed(FormMapping $mapping): void
    {
        $mapping->forceFill(['last_processed_at' => now()])->save();
    }

    public function enabledHandles(): array
    {
        return FormMapping::query()
            ->where('enabled', true)
            ->pluck('form_handle')
            ->all();
    }
}
