<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Http\Requests\StoreFollowupRequest;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Followup;
use Goldnead\Leadhub\Services\FollowupService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FollowupController extends Controller
{
    public function __construct(protected FollowupService $service)
    {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()?->hasPermission('view leadhub contacts'), 403);

        $today = $this->service->dueToday()
            ->with('contact')
            ->orderBy('due_at')
            ->get();

        $overdue = $this->service->overdue()
            ->with('contact')
            ->orderBy('due_at')
            ->get();

        $upcoming = Followup::query()
            ->active()
            ->where('due_at', '>', now()->endOfDay())
            ->with('contact')
            ->orderBy('due_at')
            ->limit(50)
            ->get();

        return view('leadhub::followups.index', [
            'today' => $today,
            'overdue' => $overdue,
            'upcoming' => $upcoming,
        ]);
    }

    public function store(StoreFollowupRequest $request, int $contactId)
    {
        $contact = Contact::query()->findOrFail($contactId);

        $followup = $this->service->set(
            $contact,
            Carbon::parse($request->string('due_at')->toString()),
            $request->input('note'),
            (string) $request->user()?->id(),
        );

        if ($request->expectsJson()) {
            return response()->json(['data' => $followup], 201);
        }

        return back()->with('success', __('leadhub::contacts.flashes.followup_set'));
    }

    public function update(Request $request, int $followupId)
    {
        abort_unless($request->user()?->hasPermission('edit leadhub contacts'), 403);

        $followup = Followup::query()->findOrFail($followupId);

        $request->validate([
            'due_at' => 'sometimes|date',
            'note' => 'sometimes|nullable|string|max:5000',
        ]);

        $followup = $this->service->update(
            $followup,
            $request->input('due_at'),
            $request->input('note'),
        );

        return back()->with('success', __('leadhub::contacts.flashes.followup_updated'));
    }

    public function complete(Request $request, int $followupId)
    {
        abort_unless($request->user()?->hasPermission('edit leadhub contacts'), 403);

        $followup = Followup::query()->findOrFail($followupId);

        $this->service->complete($followup, (string) $request->user()?->id());

        return back()->with('success', __('leadhub::contacts.flashes.followup_completed'));
    }

    public function destroy(Request $request, int $followupId)
    {
        abort_unless($request->user()?->hasPermission('edit leadhub contacts'), 403);

        $followup = Followup::query()->findOrFail($followupId);
        $this->service->remove($followup);

        return back()->with('success', __('leadhub::contacts.flashes.followup_removed'));
    }
}
