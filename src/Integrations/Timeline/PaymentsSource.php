<?php

namespace Goldnead\Leadhub\Integrations\Timeline;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\Timeline\Amount;
use Goldnead\Leadhub\Support\Timeline\TimelineEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What this person bought — read from `goldnead/statamic-payments`.
 *
 * One entry per payment (paid, pending or failed) and a second one for a
 * refund, dated when the refund happened. Line items come from
 * `payment_items`, whose `name` is the label frozen at the time of sale — a
 * product renamed later still shows what the buyer saw.
 *
 * The purchases this source lists are the same purchases payments' own bridge
 * writes into `leadhub_events` as `payments.purchase_completed`. When this
 * source runs, those bridge events are hidden ({@see self::supersedes()}), so
 * a purchase appears once.
 */
class PaymentsSource extends NeighbourSource
{
    protected const MODEL = '\Goldnead\StatamicPayments\Models\Payment';

    public function key(): string
    {
        return 'payments';
    }

    public function available(): bool
    {
        return $this->installed([ltrim(static::MODEL, '\\')], 'payments');
    }

    public function supersedes(): array
    {
        return ['payments.'];
    }

    public function entries(Contact $contact, array $emails): array
    {
        $out = [];

        foreach ($this->payments($contact, $emails) as $payment) {
            $items = $this->itemsLabel($payment);
            $amount = ['cent' => (int) $payment->amount_cent, 'currency' => (string) ($payment->currency ?: 'EUR')];
            $status = (string) $payment->status;
            $statusLabel = $this->label('payment_status', $status, $status);
            $url = $this->cpLink('utilities.payments', [], '?search='.rawurlencode((string) ($payment->provider_id ?: $payment->email)));

            $detail = [
                ['label' => __('leadhub::timeline.detail.reference'), 'value' => (string) ($payment->provider_id ?? '')],
                ['label' => __('leadhub::timeline.detail.items'), 'value' => $this->itemsDetail($payment)],
            ];

            [$kind, $color, $summaryKey] = match ($status) {
                'paid' => ['payment.paid', 'green', 'payment_paid'],
                'open', 'initiated' => ['payment.open', 'amber', 'payment_open'],
                default => ['payment.failed', 'red', 'payment_failed'],
            };

            $out[] = new TimelineEntry(
                id: 'payment:'.$payment->getKey(),
                source: $this->key(),
                kind: $kind,
                at: $status === 'paid' ? ($payment->paid_at ?? $payment->created_at) : $payment->created_at,
                summary: __('leadhub::timeline.'.$summaryKey, ['items' => $items, 'status' => $statusLabel]),
                url: $url,
                badge: ['text' => $statusLabel, 'color' => $color],
                amount: $amount,
                detail: $detail,
            );

            if ((int) ($payment->refunded_cent ?? 0) > 0) {
                $out[] = new TimelineEntry(
                    id: 'payment:'.$payment->getKey().':refund',
                    source: $this->key(),
                    kind: 'payment.refunded',
                    at: $payment->refunded_at ?? $payment->updated_at ?? $payment->created_at,
                    summary: __('leadhub::timeline.payment_refunded', ['items' => $items]),
                    url: $url,
                    badge: ['text' => $this->label('payment_status', 'refunded'), 'color' => 'purple'],
                    amount: ['cent' => (int) $payment->refunded_cent, 'currency' => $amount['currency']],
                    detail: $detail,
                );
            }
        }

        return $out;
    }

    public function stats(Contact $contact, array $emails): array
    {
        $count = 0;
        $value = [];

        foreach ($this->payments($contact, $emails) as $payment) {
            if ((string) $payment->status !== 'paid') {
                continue;
            }

            $count++;
            $currency = strtoupper((string) ($payment->currency ?: 'EUR'));
            $value[$currency] = ($value[$currency] ?? 0) + (int) $payment->amount_cent;
        }

        return ['purchase_count' => $count, 'lifetime_value' => $value];
    }

    /**
     * Payments are not brand-scoped by their own model (the shop is one pot,
     * see payments' README), but a contact is. So the rows are narrowed to
     * the contact's brand here, plus `brand_id = 0` for what a single-brand
     * install wrote before brands existed — the same two-part rule payments'
     * own `PaymentMetric` applies when it reads the table without Eloquent.
     *
     * @param  list<string>  $emails
     * @return iterable<int, object>
     */
    protected function payments(Contact $contact, array $emails): iterable
    {
        if ($emails === []) {
            return [];
        }

        return $this->remember($contact, $emails, function () use ($contact, $emails) {
            $model = static::MODEL;
            $brandId = (int) $contact->getAttribute('brand_id');

            $query = $model::query()
                ->with('items')
                ->whereIn(DB::raw('LOWER(TRIM(email))'), $emails);

            if ($brandId > 0 && $this->hasBrandColumn()) {
                $query->where(fn ($q) => $q->where('brand_id', $brandId)->orWhere('brand_id', 0));
            }

            return $query->orderByDesc('created_at')->limit(500)->get();
        });
    }

    protected function hasBrandColumn(): bool
    {
        return static::$tables['payments.brand_id'] ??= (function () {
            try {
                return Schema::hasColumn('payments', 'brand_id');
            } catch (\Throwable) {
                return false;
            }
        })();
    }

    protected function itemsLabel(object $payment): string
    {
        $items = $payment->relationLoaded('items') ? $payment->items : collect();

        $names = collect($items)->map(function ($item) {
            $qty = (int) ($item->quantity ?? 1);
            $name = (string) ($item->name ?: $item->product);

            return $qty > 1 ? $qty.' × '.$name : $name;
        })->filter()->values();

        return $names->isNotEmpty() ? $names->implode(', ') : (string) ($payment->product ?? '');
    }

    protected function itemsDetail(object $payment): string
    {
        $items = $payment->relationLoaded('items') ? $payment->items : collect();
        $currency = (string) ($payment->currency ?: 'EUR');

        return collect($items)->map(function ($item) use ($currency) {
            $qty = (int) ($item->quantity ?? 1);
            $cent = (int) ($item->amount_cent ?? 0) * max($qty, 1);

            return ((string) ($item->name ?: $item->product)).' ('.Amount::format($cent, $currency).')';
        })->implode(', ');
    }
}
