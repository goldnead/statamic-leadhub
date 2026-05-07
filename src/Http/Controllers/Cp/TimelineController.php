<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Models\Contact;
use Illuminate\Http\Request;

class TimelineController extends Controller
{
    public function index(Request $request, int $contactId)
    {
        abort_unless($request->user()?->hasPermission('view leadhub contacts'), 403);

        $contact = Contact::query()->findOrFail($contactId);

        $events = $contact->events()
            ->orderByDesc('created_at')
            ->paginate(30);

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
