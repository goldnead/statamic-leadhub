<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\FollowupRepository;
use Goldnead\Leadhub\Contracts\Repositories\FormMappingRepository;
use Goldnead\Leadhub\Http\Requests\StoreFollowupRequest;
use Goldnead\Leadhub\Services\FollowupService;
use Goldnead\Leadhub\Support\DateValueNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class FollowupController extends Controller
{
    public function __construct(
        protected ContactRepository $contacts,
        protected FollowupRepository $followupsRepo,
        protected FormMappingRepository $mappings,
        protected FollowupService $service,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view leadhub contacts');

        $shape = fn ($f) => [
            'id' => (string) ($f->uuid),
            'contact_name' => $f->contact?->displayName() ?? '—',
            'contact_url' => $f->contact ? cp_route('leadhub.contacts.show', $f->contact->id) : null,
            'due_at' => $f->due_at?->format('Y-m-d H:i'),
            'note' => $f->note,
            'complete_url' => cp_route('leadhub.followups.complete', $f->uuid),
            'delete_url' => cp_route('leadhub.followups.destroy', $f->uuid),
        ];

        return Inertia::render('leadhub::Followups/Index', [
            'overdue' => $this->service->overdue()->map($shape)->all(),
            'today' => $this->service->dueToday()->map($shape)->all(),
            'upcoming' => $this->service->upcoming(50)->map($shape)->all(),
            'configureFormsUrl' => cp_route('leadhub.forms.index'),
            'hasFormConnected' => $this->mappings->anyEnabled(),
        ]);
    }

    public function store(StoreFollowupRequest $request, int|string $contactId)
    {
        $contact = $this->contacts->find($contactId);
        abort_unless($contact, 404);

        $this->service->set(
            $contact,
            Carbon::parse($request->string('due_at')->toString()),
            $request->input('note'),
            $this->userId($request),
        );

        return back()->with('success', __('leadhub::contacts.flashes.followup_set'));
    }

    public function update(Request $request, int|string $followupId)
    {
        $this->authorizeOrFail($request, 'edit leadhub contacts');

        $followup = $this->followupsRepo->find($followupId);
        abort_unless($followup, 404);

        // Same DatePicker-object payload as the store path — normalize before
        // the `date` rule sees it. See Support\DateValueNormalizer.
        if ($request->has('due_at')) {
            $request->merge(['due_at' => DateValueNormalizer::normalize($request->input('due_at'))]);
        }

        $request->validate([
            'due_at' => 'sometimes|date',
            'note' => 'sometimes|nullable|string|max:5000',
        ]);

        $this->service->update($followup, $request->input('due_at'), $request->input('note'));

        return back()->with('success', __('leadhub::contacts.flashes.followup_updated'));
    }

    public function complete(Request $request, int|string $followupId)
    {
        $this->authorizeOrFail($request, 'edit leadhub contacts');

        $followup = $this->followupsRepo->find($followupId);
        abort_unless($followup, 404);

        $this->service->complete($followup, $this->userId($request));

        return back()->with('success', __('leadhub::contacts.flashes.followup_completed'));
    }

    public function destroy(Request $request, int|string $followupId)
    {
        $this->authorizeOrFail($request, 'edit leadhub contacts');

        $followup = $this->followupsRepo->find($followupId);
        abort_unless($followup, 404);

        $this->service->remove($followup);

        return back()->with('success', __('leadhub::contacts.flashes.followup_removed'));
    }
}
