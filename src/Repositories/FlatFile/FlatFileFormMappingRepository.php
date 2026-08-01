<?php

namespace Goldnead\Leadhub\Repositories\FlatFile;

use Goldnead\Leadhub\Contracts\Repositories\FormMappingRepository;
use Goldnead\Leadhub\Models\FormMapping;
use Illuminate\Support\Collection;

class FlatFileFormMappingRepository implements FormMappingRepository
{
    public function __construct(
        protected FileStore $files,
        protected Index $index,
        protected IndexBuilder $builder,
        protected ModelHydrator $hydrator,
    ) {}

    public function findByHandle(string $formHandle): ?FormMapping
    {
        $data = $this->files->readYaml('form-mappings.yaml');

        foreach ($data['mappings'] ?? [] as $row) {
            if (($row['form_handle'] ?? null) === $formHandle) {
                return $this->hydrator->formMapping($row);
            }
        }

        return null;
    }

    public function firstOrCreate(string $formHandle, array $defaults = []): FormMapping
    {
        $existing = $this->findByHandle($formHandle);

        if ($existing) {
            return $existing;
        }

        $now = now()->toIso8601String();
        $row = array_merge([
            'id' => $formHandle,
            'form_handle' => $formHandle,
            'enabled' => false,
            'attach_full_submission' => true,
            'default_status' => config('leadhub.default_status', 'new'),
            'created_at' => $now,
            'updated_at' => $now,
        ], $defaults);

        $data = $this->files->readYaml('form-mappings.yaml');
        $data['mappings'] = $data['mappings'] ?? [];
        $data['mappings'][] = $row;
        $this->files->writeYaml('form-mappings.yaml', $data);

        $this->builder->rebuildFormMappings($this->index);

        return $this->hydrator->formMapping($row);
    }

    public function update(FormMapping $mapping, array $attributes): FormMapping
    {
        $data = $this->files->readYaml('form-mappings.yaml');
        $changed = false;

        foreach (($data['mappings'] ?? []) as $i => $row) {
            if (($row['form_handle'] ?? null) === $mapping->form_handle) {
                $row = array_merge($row, $attributes);
                $row['updated_at'] = now()->toIso8601String();
                $data['mappings'][$i] = $row;
                $changed = true;
                $this->files->writeYaml('form-mappings.yaml', $data);
                $this->builder->rebuildFormMappings($this->index);

                return $this->hydrator->formMapping($row);
            }
        }

        // Not found — create.
        if (! $changed) {
            return $this->firstOrCreate($mapping->form_handle, $attributes);
        }

        return $mapping;
    }

    public function delete(FormMapping $mapping): void
    {
        $data = $this->files->readYaml('form-mappings.yaml');
        $data['mappings'] = array_values(array_filter(
            $data['mappings'] ?? [],
            fn ($row) => ($row['form_handle'] ?? null) !== $mapping->form_handle,
        ));
        $this->files->writeYaml('form-mappings.yaml', $data);
        $this->builder->rebuildFormMappings($this->index);
    }

    public function all(): Collection
    {
        $data = $this->files->readYaml('form-mappings.yaml');

        return collect($data['mappings'] ?? [])->map(fn ($r) => $this->hydrator->formMapping($r));
    }

    public function anyEnabled(): bool
    {
        return $this->all()->contains(fn (FormMapping $m) => $m->enabled);
    }

    public function markProcessed(FormMapping $mapping): void
    {
        $this->update($mapping, ['last_processed_at' => now()->toIso8601String()]);
    }

    public function enabledHandles(): array
    {
        return $this->all()
            ->filter(fn (FormMapping $m) => $m->enabled)
            ->pluck('form_handle')
            ->all();
    }
}
