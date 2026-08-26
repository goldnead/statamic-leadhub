<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\CustomField;
use Illuminate\Support\Collection;

/**
 * Writing and reading the values of site-defined fields.
 *
 * A thin service rather than methods on the contact, because every write has to
 * pass the definition first: a value stored in the shape it arrived in — "20"
 * for a number, "on" for a checkbox — turns every later comparison into a
 * guess, and `"20" > 40` is a different question from `20 > 40`.
 */
class CustomFieldService
{
    /**
     * @return Collection<int, CustomField>
     */
    public function definitions(): Collection
    {
        // The definitions are a table, and the flat-file driver has no tables
        // beyond settings — its ServiceProvider registers only that one
        // migration. Asking anyway took the whole segment area down with a
        // "no such table", not just this feature; the segment builder calls
        // this on every render.
        //
        // The addon's own test suite could not see it: TestCase migrates
        // everything regardless of driver, so a green flat-file run proves
        // nothing about a table the flat-file driver deliberately skips.
        if (config('leadhub.storage.driver', 'eloquent') !== 'eloquent') {
            return collect();
        }

        return CustomField::query()->orderBy('sort')->orderBy('label')->get();
    }

    /**
     * Merge written values into a contact, cast and validated.
     *
     * Values whose field nobody defined are dropped rather than stored. Keeping
     * them would leave a value nothing can read and nothing can segment on —
     * present in the row, absent from the interface, which is the shape of a
     * bug people spend an afternoon on.
     *
     * @param  array<string, mixed>  $eingaben
     */
    public function apply(Contact $contact, array $eingaben): Contact
    {
        // Resolved against the CONTACT's brand, not whichever one happens to be
        // current. A queue worker, a console command or a webhook has no active
        // brand, and `definitions()` would come back empty there — every value
        // silently dropped, by the very method whose job is to keep unknown
        // ones out.
        $felder = BrandContext::runFor(
            $contact->brand_id,
            fn (): Collection => $this->definitions(),
        )->keyBy('handle');
        $werte = is_array($contact->custom_fields) ? $contact->custom_fields : [];

        foreach ($eingaben as $handle => $roh) {
            $feld = $felder->get($handle);

            if (! $feld instanceof CustomField) {
                continue;
            }

            $wert = $feld->cast($roh);

            // Null removes rather than stores. A field cleared in the form and
            // a field never filled in should read the same way to a segment;
            // storing an explicit null would make `is_set` true for an empty
            // value.
            if ($wert === null) {
                unset($werte[$handle]);

                continue;
            }

            $werte[$handle] = $wert;
        }

        $contact->custom_fields = $werte;

        return $contact;
    }

    /**
     * What the segment builder offers: every field with the comparisons that
     * make sense for it.
     *
     * @return list<array<string, mixed>>
     */
    public function forRuleBuilder(): array
    {
        return $this->definitions()->map(static fn (CustomField $f): array => [
            'handle' => $f->handle,
            'label' => $f->label,
            'type' => $f->type,
            'operators' => $f->operators(),
            'options' => $f->options ?? [],
        ])->values()->all();
    }
}
