<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\EventRepository;
use Illuminate\Http\Request;

class TimelineController extends Controller
{
    public function __construct(
        protected ContactRepository $contacts,
        protected EventRepository $events,
    ) {
    }

    public function index(Request $request, int|string $contactId)
    {
        abort_unless($request->user()?->can('view leadhub contacts'), 403);

        $contact = $this->contacts->find($contactId);
        abort_unless($contact, 404);

        $events = $this->events->forContact(
            $contact,
            perPage: 30,
            page: (int) $request->input('page', 1),
        );

        return response()->json([
            'data' => $events->items(),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'total' => $events->total(),
            ],
        ]);
    }
}
