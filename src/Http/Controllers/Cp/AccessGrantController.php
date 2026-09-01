<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\EventRepository;
use Goldnead\Leadhub\Integrations\Entitlements\AccessGranter;
use Goldnead\Leadhub\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The "grant access" button on the contact screen.
 *
 * Permission first, then whether entitlements is even installed: a user
 * without the right sees 403 whatever is installed, which is what
 * `CpWriteRouteAuthorizationTest` holds every write route to. A user with the
 * right on an install without entitlements gets 404, the same answer as the
 * button they never saw.
 */
class AccessGrantController extends Controller
{
    public function __construct(
        protected ContactRepository $contacts,
        protected EventRepository $events,
        protected AccessGranter $granter,
    ) {}

    public function store(Request $request, int|string $contactId)
    {
        $this->authorizeOrFail($request, 'grant leadhub access');

        $contact = $this->contacts->find($contactId);
        abort_if($contact === null, 404);
        abort_unless($this->granter->available(), 404);

        $options = array_column($this->granter->options(), 'value');

        $validated = $request->validate([
            'product' => ['required', 'string', Rule::in($options)],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'product.in' => __('leadhub::contacts.access_grant.unknown_product'),
        ]);

        try {
            $ids = $this->granter->grant($contact, $validated['product'], $validated['note'] ?? null, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['product' => $e->getMessage()]);
        }

        $label = collect($this->granter->options())->firstWhere('value', $validated['product'])['label'] ?? $validated['product'];

        $this->events->record(
            $contact,
            Event::TYPE_ACCESS_GRANTED,
            __('leadhub::timeline.access_granted', ['product' => $label]),
            [
                'product' => $validated['product'],
                'slugs' => $this->granter->slugsFor($validated['product']),
                'entitlement_ids' => $ids,
                'note' => $validated['note'] ?? null,
                'detail' => array_values(array_filter([
                    ['label' => __('leadhub::timeline.detail.note'), 'value' => $validated['note'] ?? null],
                ], fn ($line) => $line['value'] !== null && $line['value'] !== '')),
            ],
            'user',
            $this->userId($request) ?: null,
        );

        return back()->with('success', __('leadhub::contacts.flashes.access_granted', ['product' => $label]));
    }
}
