<?php

namespace Goldnead\Leadhub\Services;

use DateTimeInterface;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\RevenueEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * What a contact has paid, kept as a ledger and cached as a total.
 *
 * Eloquent driver only, like {@see OpportunityService}: the flat-file store has
 * no table to aggregate over, and a sum computed in PHP across every contact
 * file is not a feature, it is a timeout.
 *
 * Nothing here knows what a product is, what a checkout is, or which sibling
 * addon called. It takes an amount, a currency and a reference, and it refuses
 * to take the same reference twice.
 */
class RevenueService
{
    /**
     * Record money a contact paid.
     *
     * The reference is the whole of the idempotency, and it is enforced by a
     * unique index rather than by looking first. A webhook delivered twice, a
     * job retried, a queue that lost its acknowledgement — all of them arrive
     * here as a second insert with the same reference, and the database is the
     * only participant that can decide the race. The read below is an
     * optimisation for the common case, never the guard.
     *
     * @param  string  $reference  Namespaced by the contributor, e.g. "payments:payment:41".
     * @param  int  $amountCent  Gross, in the currency's minor unit. Never negative.
     * @param  array<string,mixed>  $meta  Free-form, for the contributor's own use.
     */
    public function record(
        Contact $contact,
        string $reference,
        int $amountCent,
        string $currency,
        ?DateTimeInterface $occurredAt = null,
        ?string $source = null,
        array $meta = [],
    ): ?RevenueEntry {
        $reference = trim($reference);
        $currency = strtoupper(trim($currency));

        // Loud, because every one of these is a caller that is wrong rather
        // than a visitor who typed something odd. A silently dropped payment is
        // the failure this whole service exists to prevent.
        if ($reference === '') {
            throw new InvalidArgumentException('A revenue entry needs a reference; without one it cannot be written twice safely.');
        }

        if ($amountCent < 0) {
            throw new InvalidArgumentException('A revenue entry cannot be negative. Money that went back is a refund on an existing entry.');
        }

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('A revenue entry needs an ISO 4217 currency code, got: '.$currency);
        }

        if ($existing = $this->findByReference($reference)) {
            $entry = $this->guardBrand($existing, $contact, $reference);

            // A second delivery is the cheapest chance to repair a cache that
            // went out of step — a half-deployed bridge, a crash between the
            // insert and the recompute. Doing nothing here would mean the only
            // repair is a command somebody has to know about.
            if ($entry !== null) {
                $this->recalculate($contact);
            }

            return $entry;
        }

        $this->warnOnMixedCurrency($contact, $currency);

        $occurredAt = $occurredAt ? Carbon::parse($occurredAt) : Carbon::now();

        try {
            $entry = RevenueEntry::create([
                // The money belongs to whoever owns the person, not to whatever
                // brand happened to be active. A payment webhook runs with no
                // brand context at all, and `HasBrand` would otherwise stamp
                // the default one — right on a single-brand site, and quietly
                // wrong on every other. Set explicitly, because the trait only
                // fills an empty column.
                'brand_id' => $contact->brand_id,
                'contact_id' => $contact->getKey(),
                'reference' => $reference,
                'source' => $source,
                'amount_cent' => $amountCent,
                'refunded_cent' => 0,
                'currency' => $currency,
                'occurred_at' => $occurredAt,
                'meta' => $meta ?: null,
            ]);
        } catch (QueryException $e) {
            // The other writer won. Its row is as good as the one this call
            // would have written — same reference means the same fact — so the
            // honest answer is theirs, not an error.
            $entry = $this->findByReference($reference);

            if ($entry === null) {
                throw $e;
            }

            return $this->guardBrand($entry, $contact, $reference);
        }

        $this->recalculate($contact);

        return $entry;
    }

    /**
     * Money that went back on an entry that already exists.
     *
     * Takes the **running total** refunded, not this one movement. A caller
     * that knows the total is idempotent for free; a caller that added a delta
     * each time would double-count on a redelivered webhook, and the ledger
     * would be wrong in the one direction nobody checks.
     *
     * Deliberately not clamped to the original amount. A chargeback can cost
     * more than the sale, and a ledger that refuses to say so is a ledger that
     * quietly disagrees with the bank statement.
     */
    public function refund(string $reference, int $refundedCent): ?RevenueEntry
    {
        if ($refundedCent < 0) {
            throw new InvalidArgumentException('A refund total cannot be negative.');
        }

        $entry = $this->findByReference(trim($reference));

        if ($entry === null) {
            // Nothing to correct. Said out loud because it means a refund
            // arrived for a sale this addon never heard about — the bridge was
            // switched on between the two, or the reference changed shape.
            Log::warning('leadhub: a refund was reported for a revenue entry that does not exist.', [
                'reference' => $reference,
            ]);

            return null;
        }

        if ($entry->refunded_cent === $refundedCent) {
            return $entry;
        }

        $entry->forceFill(['refunded_cent' => $refundedCent])->save();

        // Unscoped for the same reason `findByReference()` is: a refund arrives
        // from a webhook with no brand resolved, and the entry has already
        // named its contact. Re-asking the brand scope here could only turn a
        // known id into "not found".
        if ($contact = Contact::withoutGlobalScopes()->find($entry->contact_id)) {
            $this->recalculate($contact);
        }

        return $entry;
    }

    /** The entries behind the totals, newest first. */
    public function forContact(Contact $contact, int $limit = 50): Collection
    {
        return RevenueEntry::withoutGlobalScopes()
            ->where('contact_id', $contact->getKey())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Rebuild the cached totals from the ledger.
     *
     * One statement, no read in PHP. A read-then-write here would lose a
     * concurrent entry: two purchases landing together would both read the
     * total before the other's row, and the second write would erase the first.
     * Computed inside the database, the worst a race can do is run twice and
     * arrive at the same answer.
     *
     * Public because it is also the repair: a host that imported rows directly,
     * or ran with a half-deployed bridge, calls this and the cache is true again.
     */
    public function recalculate(Contact $contact): void
    {
        $id = (int) $contact->getKey();

        DB::update(
            'update leadhub_contacts set '.
            'revenue_cent = (select coalesce(sum(amount_cent), 0) from leadhub_contact_revenue where contact_id = ?), '.
            'revenue_refunded_cent = (select coalesce(sum(refunded_cent), 0) from leadhub_contact_revenue where contact_id = ?), '.
            'purchase_count = (select count(*) from leadhub_contact_revenue where contact_id = ?), '.
            'revenue_currency = (select currency from leadhub_contact_revenue where contact_id = ? order by occurred_at desc, id desc limit 1), '.
            'first_purchase_at = (select min(occurred_at) from leadhub_contact_revenue where contact_id = ?), '.
            'last_purchase_at = (select max(occurred_at) from leadhub_contact_revenue where contact_id = ?) '.
            'where id = ?',
            [$id, $id, $id, $id, $id, $id, $id],
        );

        // The model in the caller's hand still carries the old numbers, and the
        // caller is usually about to show them.
        $contact->refresh();
    }

    /**
     * Look a reference up across every brand.
     *
     * Unscoped on purpose: the uniqueness this service relies on is global, so
     * the check has to be global too. A brand-scoped read would miss a
     * collision and then hit the unique index anyway — with an exception
     * instead of an answer.
     */
    protected function findByReference(string $reference): ?RevenueEntry
    {
        if ($reference === '') {
            return null;
        }

        return RevenueEntry::withoutGlobalScopes()
            ->where('reference', $reference)
            ->first();
    }

    /**
     * A total in one currency and a sale in another cannot be added up.
     *
     * Not refused, because refusing would lose the sale over a reporting
     * problem. Said out loud, because the cached total silently becomes a
     * number with no meaning — 100 EUR plus 100 CHF labelled "200 CHF" — and
     * that is the failure mode nobody spots by looking at it.
     */
    protected function warnOnMixedCurrency(Contact $contact, string $currency): void
    {
        $bisher = $contact->revenue_currency;

        if (is_string($bisher) && $bisher !== '' && $bisher !== $currency) {
            Log::warning('leadhub: a contact is being credited in a second currency; the cached total mixes them and cannot be read as money.', [
                'contact_id' => $contact->getKey(),
                'existing_currency' => $bisher,
                'new_currency' => $currency,
            ]);
        }
    }

    /**
     * The same reference under a different contact or brand is a bug, not a duplicate.
     *
     * Returning the foreign entry would attach one brand's money to another
     * brand's contact, and the totals would be wrong on both sides with nothing
     * to see. Refusing is the smaller damage.
     */
    protected function guardBrand(RevenueEntry $entry, Contact $contact, string $reference): ?RevenueEntry
    {
        if ((int) $entry->contact_id === (int) $contact->getKey()
            && (int) $entry->brand_id === (int) $contact->brand_id) {
            return $entry;
        }

        Log::error('leadhub: a revenue reference already belongs to another contact or brand; nothing was written.', [
            'reference' => $reference,
            'belongs_to_contact' => $entry->contact_id,
            'belongs_to_brand' => $entry->brand_id,
            'offered_for_contact' => $contact->getKey(),
            'offered_for_brand' => $contact->brand_id,
        ]);

        return null;
    }
}
