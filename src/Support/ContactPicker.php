<?php

namespace Goldnead\Leadhub\Support;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;

/**
 * Option lists for the contact pickers on the task and opportunity forms.
 *
 * There is no contact-picker component in the addon, and a plain `<Select>`
 * over every contact stops working somewhere in the low thousands. So the
 * forms get a first page plus a search endpoint, and the CP `<Combobox>`
 * queries it.
 *
 * Everything here goes through the ContactRepository, which is brand-scoped —
 * a picker that offered contacts of another brand would be a cross-brand leak
 * with a friendly interface.
 */
class ContactPicker
{
    public const LIMIT = 50;

    public function __construct(protected ContactRepository $contacts)
    {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function options(?string $search = null, ?string $selectedId = null): array
    {
        $page = $this->contacts->paginate(
            array_filter(['search' => $search, 'sort' => 'last_activity_at', 'direction' => 'desc']),
            self::LIMIT,
            1,
        );

        $options = collect($page->items())
            ->map(fn ($contact) => $this->option($contact))
            ->all();

        // The record already selected must be in the list, or an edit form
        // renders an empty picker for a contact that is plainly there.
        if (filled($selectedId) && ! collect($options)->pluck('value')->contains((string) $selectedId)) {
            if ($selected = $this->contacts->find($selectedId)) {
                array_unshift($options, $this->option($selected));
            }
        }

        return $options;
    }

    protected function option($contact): array
    {
        $name = $contact->displayName();
        $email = $contact->email;

        return [
            'value' => (string) $contact->id,
            'label' => $email && $email !== $name ? "{$name} ({$email})" : $name,
        ];
    }
}
