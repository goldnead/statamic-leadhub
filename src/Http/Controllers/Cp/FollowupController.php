<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\FollowupRepository;
use Goldnead\Leadhub\Http\Requests\StoreFollowupRequest;
use Goldnead\Leadhub\Services\FollowupService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FollowupController extends Controller
{
    public function __construct(
        protected ContactRepository $contacts,
        protected FollowupRepository $followupsRepo,
        protected FollowupService $service,
    ) {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()?->hasPermission('view leadhub contacts'), 403);

        return view('leadhub::followups.index', [
            'today' => $this->service->dueToday(),
            'overdue' => $this->service->overdue(),
            'upcoming' => $this->service->upcoming(50),
        ]);
    }

    public function store(StoreFollowupRequest $request, int|string $contactId)
    {
        $contact = $this->contacts->find($contactId);
        abort_unless($contact, 404);

        $followup = $this->service->set(
            $contact,
            Carbon::parse($request->string('due_at')->toString()),
            $request->input('note'),
            (string) ($request->user()?->id() ?? ''),
        );

        if ($request->expectsJson()) {
            return response()->json(['data' => $followup], 201);
        }

        return back()->with('success', __('leadhub::contacts.flashes.followup_set'));
    }

    public function update(Request $request, int|string $followupId)
    {
        abort_unless($request->user()?->hasPermission('edit leadhub contacts'), 403);

        $followup = $this->followupsRepo->find($followupId);
        abort_unless($followup, 404);

        $request->validate([
            'due_at' => 'sometimes|date',
            'note' => 'sometimes|nullable|string|max:5000',
        ]);

        $this->service->update(
            $followup,
            $request->input('due_at'),
            $request->input('note'),
        );

        return back()->with('success', __('leadhub::contacts.flashes.followup_updated'));
    }

    public function complete(Request $request, int|string $followupId)
    {
        abort_unless($request->user()?->hasPermission('edit leadhub contacts'), 403);

        $followup = $this->followupsRepo->find($followupId);
        abort_unless($followup, 404);

        $this->service->complete($followup, (string) ($request->user()?->id() ?? ''));

        return back()->with('success', __('leadhub::contacts.flashes.followup_completed'));
    }

    public function destroy(Request $request, int|string $followupId)
    {
        abort_unless($request->user()?->hasPermission('edit leadhub contacts'), 403);

        $followup = $this->followupsRepo->find($followupId);
        abort_unless($followup, 404);

        $this->service->remove($followup);

        return back()->with('success', __('leadhub::contacts.flashes.followup_removed'));
    }
}
