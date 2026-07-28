<?php

namespace Goldnead\Leadhub\Support;

use Goldnead\Leadhub\Models\Opportunity;

/**
 * Option list for the opportunity picker on the task form.
 *
 * Deliberately scoped to one contact. A flat list of every deal in the
 * install is not a picker, it is a haystack — and a task attached to the deal
 * of a different contact is a data error that no screen would ever surface
 * again. So: no contact selected, no options. The form says so rather than
 * showing an empty dropdown.
 *
 * The query runs through the Opportunity model, so the brand-context global
 * scope applies. A raw query builder here would offer another brand's deals,
 * which is the trap ResolvesCrmReferences documents for `exists:`.
 */
class OpportunityPicker
{
    public const LIMIT = 100;

    /**
     * Open opportunities of one contact, plus whichever one is already
     * selected — a closed deal that a task still hangs on has to stay
     * visible in the edit form, or saving the form would silently detach it.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function optionsForContact(mixed $contactId, ?string $selectedId = null): array
    {
        if (blank($contactId)) {
            return $this->selectedOnly($selectedId);
        }

        $options = Opportunity::query()
            ->where('contact_id', $contactId)
            ->open()
            ->orderByDesc('last_activity_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Opportunity $o) => $this->option($o))
            ->all();

        if (filled($selectedId) && ! collect($options)->pluck('value')->contains((string) $selectedId)) {
            if ($selected = Opportunity::query()->find($selectedId)) {
                array_unshift($options, $this->option($selected));
            }
        }

        return $options;
    }

    protected function selectedOnly(?string $selectedId): array
    {
        if (blank($selectedId)) {
            return [];
        }

        $selected = Opportunity::query()->find($selectedId);

        return $selected ? [$this->option($selected)] : [];
    }

    protected function option(Opportunity $opportunity): array
    {
        return [
            'value' => (string) $opportunity->id,
            'label' => (string) $opportunity->title,
        ];
    }
}
