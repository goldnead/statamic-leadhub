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
use Statamic\CP\Column;

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

        // One flat list with a `bucket` per row, not three arrays.
        //
        // The screen used to be three panels of cards, which the CP does
        // nowhere: a list of records is a table, and a table is what somebody
        // can sort, search and scan. The grouping survives as a column.
        $shape = fn ($f, string $bucket) => [
            'id' => (string) ($f->uuid),
            'contact_name' => $f->contact?->displayName() ?? '—',
            'contact_url' => $f->contact ? cp_route('leadhub.contacts.show', $f->contact->id) : null,
            'due_at' => $f->due_at?->format('Y-m-d H:i'),
            'note' => $f->note,
            'bucket' => $bucket,
            'bucket_label' => __('leadhub::followups.sections.'.$bucket),
            'complete_url' => cp_route('leadhub.followups.complete', $f->uuid),
            'delete_url' => cp_route('leadhub.followups.destroy', $f->uuid),
        ];

        $rows = collect()
            ->concat($this->service->overdue()->map(fn ($f) => $shape($f, 'overdue')))
            ->concat($this->service->dueToday()->map(fn ($f) => $shape($f, 'today')))
            ->concat($this->service->upcoming(50)->map(fn ($f) => $shape($f, 'upcoming')))
            ->values()
            ->all();

        $columns = collect([
            Column::make('contact_name')->label(__('Contact')),
            Column::make('due_at')->label(__('leadhub::followups.fields.due_at')),
            Column::make('bucket_label')->label(__('Status')),
            Column::make('note')->label(__('leadhub::followups.fields.note')),
        ])->map(fn ($c) => $c->toArray())->all();

        return Inertia::render('leadhub::Followups/Index', [
            'followups' => $rows,
            'columns' => $columns,
            'counts' => [
                'overdue' => collect($rows)->where('bucket', 'overdue')->count(),
                'today' => collect($rows)->where('bucket', 'today')->count(),
                'upcoming' => collect($rows)->where('bucket', 'upcoming')->count(),
            ],
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
