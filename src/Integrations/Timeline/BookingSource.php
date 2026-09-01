<?php

namespace Goldnead\Leadhub\Integrations\Timeline;

use Carbon\Carbon;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\Timeline\TimelineEntry;
use Illuminate\Support\Facades\DB;

/**
 * When this person was here — read from `goldnead/statamic-booking`.
 *
 * A booking is made by whoever filled the form and carries no contact link,
 * so the match is by address. Dated by the appointment, not by the click that
 * made it: "she was here on the 12th" is the fact the timeline is for.
 */
class BookingSource extends NeighbourSource
{
    protected const MODEL = '\Goldnead\StatamicBooking\Models\Booking';

    public function key(): string
    {
        return 'booking';
    }

    public function available(): bool
    {
        return $this->installed([ltrim(static::MODEL, '\\')], 'bookings');
    }

    public function entries(Contact $contact, array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        $model = static::MODEL;
        $url = $this->cpLink('utilities.bookings');
        $out = [];

        $bookings = $model::query()
            ->whereIn(DB::raw('LOWER(TRIM(email))'), $emails)
            ->orderByDesc('scheduled_at')
            ->limit(500)
            ->get();

        foreach ($bookings as $booking) {
            $status = (string) $booking->status;
            $at = $booking->scheduled_at ?? $booking->created_at;

            $out[] = new TimelineEntry(
                id: 'booking:'.$booking->getKey(),
                source: $this->key(),
                kind: 'booking.'.$status,
                at: $at,
                summary: __('leadhub::timeline.booking', [
                    'endpoint' => (string) $booking->endpoint,
                    'date' => $at ? Carbon::parse($at)->locale((string) app()->getLocale())->isoFormat('LLL') : '',
                ]),
                url: $url,
                badge: ['text' => $this->label('booking_status', $status, $status), 'color' => $this->color($status)],
                detail: [
                    ['label' => __('leadhub::timeline.detail.duration'), 'value' => $booking->duration_minutes ? $booking->duration_minutes.' min' : ''],
                    ['label' => __('leadhub::timeline.detail.meeting'), 'value' => (string) ($booking->meeting_url ?? '')],
                ],
            );
        }

        return $out;
    }

    protected function color(string $status): string
    {
        return match ($status) {
            'booked' => 'green',
            'rescheduled' => 'blue',
            'requested' => 'amber',
            'rejected' => 'red',
            default => 'default',
        };
    }
}
