<?php

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\CustomField;
use Goldnead\Leadhub\Models\Segment;
use Goldnead\Leadhub\Services\CustomFieldService;
use Goldnead\Leadhub\Support\SegmentEvaluator;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\User;

/**
 * Values a site keeps about its own contacts.
 *
 * Until now a tag was the only place to put anything that is not name, email or
 * status — and a tag can only say yes or no. Voice part, choir size, region:
 * those are values, and pressed into tags they become `chorgroesse-20-40`,
 * `chorgroesse-40-60`, and a segment nobody can maintain.
 *
 * The test that matters is the last group: a field nobody can segment on is
 * decoration.
 */
beforeEach(function (): void {
    // Eloquent only, by design. The definitions are a table and the values a
    // JSON column; the flat driver has neither, and its ServiceProvider
    // deliberately registers no migration for them. Under LEADHUB_DRIVER=flat
    // every test here would fail on a table that is meant to be absent.
    //
    // The two flat-driver tests at the bottom of this file are not an
    // exception to that: they run in the eloquent leg of the matrix and switch
    // the driver themselves, which is the only way to ask what production asks
    // — a flat install whose table was never created. The addon's own flat
    // matrix cannot pose that question, because TestCase migrates everything
    // regardless of driver.
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('Eigene Felder sind eine Tabelle; der Flat-Treiber hat sie nicht.');
    }

    $this->feld = fn (array $a = []) => CustomField::create(array_merge([
        'handle' => 'stimmlage', 'label' => 'Stimmlage', 'type' => CustomField::TYPE_TEXT,
    ], $a));

    $this->kontakt = fn (array $werte = []) => tap(Contact::create(['email' => 'wer'.uniqid().'@example.test']),
        fn (Contact $c) => app(CustomFieldService::class)->apply($c, $werte)->save());
});

it('stores a value under the handle its field defines', function (): void {
    ($this->feld)();

    $kontakt = ($this->kontakt)(['stimmlage' => 'Alt']);

    expect($kontakt->fresh()->custom_fields)->toBe(['stimmlage' => 'Alt']);
});

it('casts a written value into the shape the field promises', function (): void {
    ($this->feld)(['handle' => 'chorgroesse', 'type' => CustomField::TYPE_NUMBER]);
    ($this->feld)(['handle' => 'aktiv', 'type' => CustomField::TYPE_BOOLEAN]);
    ($this->feld)(['handle' => 'beitritt', 'type' => CustomField::TYPE_DATE]);

    // Straight from a form: a number as a string, a checkbox as "on", a date in
    // whatever the input produced. Stored as they arrive, every later
    // comparison becomes a guess — "20" > 40 is a different question from
    // 20 > 40, and only one of them has the answer somebody meant.
    $werte = ($this->kontakt)(['chorgroesse' => '45', 'aktiv' => 'on', 'beitritt' => '01.03.2024'])
        ->fresh()->custom_fields;

    expect($werte['chorgroesse'])->toBe(45)
        ->and($werte['aktiv'])->toBeTrue()
        ->and($werte['beitritt'])->toBe('2024-03-01');
});

it('drops a value whose field nobody defined', function (): void {
    ($this->feld)();

    // Storing it would leave a value nothing can read and nothing can segment
    // on: present in the row, absent from the interface.
    expect(($this->kontakt)(['stimmlage' => 'Alt', 'erfunden' => 'x'])->fresh()->custom_fields)
        ->toBe(['stimmlage' => 'Alt']);
});

it('removes rather than stores an emptied value', function (): void {
    ($this->feld)();
    $kontakt = ($this->kontakt)(['stimmlage' => 'Alt']);

    app(CustomFieldService::class)->apply($kontakt, ['stimmlage' => ''])->save();

    // A field cleared in the form and one never filled in have to read the same
    // way to a segment. An explicit null would make `is_set` true for nothing.
    expect($kontakt->fresh()->custom_fields)->toBe([]);
});

it('refuses a select value that is not one of the options', function (): void {
    ($this->feld)(['handle' => 'bundesland', 'type' => CustomField::TYPE_SELECT, 'options' => [
        ['value' => 'hh', 'label' => 'Hamburg'], ['value' => 'by', 'label' => 'Bayern'],
    ]]);

    expect(($this->kontakt)(['bundesland' => 'xx'])->fresh()->custom_fields)->toBe([])
        ->and(($this->kontakt)(['bundesland' => 'hh'])->fresh()->custom_fields)->toBe(['bundesland' => 'hh']);
});

/*
 * The half that makes the other half worth having.
 */
it('lets a segment compare on a value, not just on yes or no', function (): void {
    ($this->feld)(['handle' => 'chorgroesse', 'type' => CustomField::TYPE_NUMBER]);

    $gross = ($this->kontakt)(['chorgroesse' => 80]);
    $klein = ($this->kontakt)(['chorgroesse' => 12]);

    $regeln = ['match' => 'all', 'conditions' => [
        ['type' => 'custom', 'field' => 'chorgroesse', 'operator' => 'gte', 'value' => 40],
    ]];

    $auswerter = app(SegmentEvaluator::class);

    // This is the whole point: `gte`. In tags this question needed
    // `chorgroesse-40-60`, `chorgroesse-60-80` and a segment listing them all.
    expect($auswerter->matches($gross->fresh(), $regeln))->toBeTrue()
        ->and($auswerter->matches($klein->fresh(), $regeln))->toBeFalse();
});

it('matches nobody on a field that no longer exists', function (): void {
    ($this->feld)();
    $kontakt = ($this->kontakt)(['stimmlage' => 'Alt']);

    $regeln = ['match' => 'all', 'conditions' => [
        ['type' => 'custom', 'field' => 'geloescht', 'operator' => 'is_set'],
    ]];

    // The safer direction. A segment whose field was deleted empties out rather
    // than quietly matching everybody.
    expect(app(SegmentEvaluator::class)->matches($kontakt->fresh(), $regeln))->toBeFalse();
});

it('offers only comparisons that can be true for the type', function (): void {
    $zahl = ($this->feld)(['handle' => 'z', 'type' => CustomField::TYPE_NUMBER]);
    $jaNein = ($this->feld)(['handle' => 'j', 'type' => CustomField::TYPE_BOOLEAN]);

    // A date offering "contains" or a yes/no offering "greater than" turns the
    // rule builder into a place where you can write a condition that can never
    // be true, with nothing saying so.
    expect($zahl->operators())->toContain('gte')->not->toContain('contains')
        ->and($jaNein->operators())->toContain('is_true')->not->toContain('gte');

    // And every operator offered has to be one the evaluator actually knows.
    $bekannt = ['eq', 'neq', 'in', 'not_in', 'contains', 'starts_with', 'gt', 'gte', 'lt', 'lte',
        'is_set', 'is_empty', 'is_true', 'is_false', 'before', 'after', 'within_days', 'older_than_days'];

    // Not `expect(...)->toContain($op, $meldung)`: Pest reads further
    // arguments as further needles, so the message became something the array
    // had to contain too. Cost twenty minutes once; written plainly here.
    $unbekannt = [];

    foreach (CustomField::types() as $typ) {
        foreach ((new CustomField(['type' => $typ]))->operators() as $op) {
            if (! in_array($op, $bekannt, true)) {
                $unbekannt[] = "{$typ}:{$op}";
            }
        }
    }

    expect($unbekannt)->toBe([]);
});

it('keeps the recorded values when a definition is deleted', function (): void {
    $feld = ($this->feld)();
    $kontakt = ($this->kontakt)(['stimmlage' => 'Alt']);

    $feld->delete();

    // Unreadable, not gone. Sweeping the values would be an irreversible loss
    // behind a button labelled "delete field"; this is recoverable by defining
    // the handle again.
    expect($kontakt->fresh()->custom_fields)->toBe(['stimmlage' => 'Alt']);
});

/*
 * The half the ticket is most likely to lose: a field nobody can segment on is
 * decoration. Written after checking, because the first version of this build
 * had exactly that gap — the service could describe the fields and the segment
 * builder was never handed them.
 */
it('hands the fields to the segment builder', function (): void {
    $this->actingAs(User::make()->email('cf-admin@example.com')->makeSuper()->save());

    ($this->feld)(['handle' => 'chorgroesse', 'label' => 'Chorgröße', 'type' => CustomField::TYPE_NUMBER]);

    // X-Inertia, like every other CP test here: without it the response is the
    // full page and needs the host application's `app` view, which a package
    // test suite does not have.
    $antwort = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.segments.create'))->assertOk();

    $wortschatz = json_decode($antwort->getContent(), true)['props']['vocabulary'] ?? [];

    expect($wortschatz['custom_fields'] ?? [])->toHaveCount(1);

    $feld = $wortschatz['custom_fields'][0];

    expect($feld['handle'])->toBe('chorgroesse')
        ->and($feld['label'])->toBe('Chorgröße')
        // With the operators, or the builder would offer "contains" on a number.
        ->and($feld['operators'])->toContain('gte')
        ->and($feld['operators'])->not->toContain('contains');
});

it('offers the select options to the builder, so a value is picked and not typed', function (): void {
    $this->actingAs(User::make()->email('cf-admin2@example.com')->makeSuper()->save());

    ($this->feld)(['handle' => 'bundesland', 'type' => CustomField::TYPE_SELECT, 'options' => [
        ['value' => 'hh', 'label' => 'Hamburg'],
    ]]);

    $antwort = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.segments.create'))->assertOk();

    $wortschatz = json_decode($antwort->getContent(), true)['props']['vocabulary'] ?? [];

    // A typed value that is not an option would be stored, evaluated, and match
    // nobody — the rule looks right and is always false.
    expect($wortschatz['custom_fields'][0]['options'])->toBe([['value' => 'hh', 'label' => 'Hamburg']]);
});

/*
 * The routes, which the first version of this test file never asked for.
 *
 * That is how the whole screen shipped dead: every one of its four actions
 * called a method that did not exist on the base controller, 566 tests stayed
 * green, and nothing between the model and the browser was ever exercised. A
 * suite that never crosses into HTTP cannot see a 500.
 */
it('serves all four screens of the field editor', function (): void {
    $this->actingAs(User::make()->email('cf-routes@example.com')->makeSuper()->save());

    $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.custom-fields.index'))->assertOk();

    $this->post(cp_route('leadhub.custom-fields.store'), [
        'handle' => 'stimmlage', 'label' => 'Stimmlage', 'type' => 'text',
    ])->assertRedirect();

    $feld = CustomField::query()->sole();

    $this->patch(cp_route('leadhub.custom-fields.update', $feld->id), [
        'label' => 'Stimmgruppe', 'type' => 'text',
    ])->assertRedirect();

    expect($feld->fresh()->label)->toBe('Stimmgruppe')
        ->and($feld->fresh()->handle)->toBe('stimmlage', 'der Handle bleibt, jeder Wert haengt daran');

    $this->delete(cp_route('leadhub.custom-fields.destroy', $feld->id))->assertRedirect();

    expect(CustomField::query()->count())->toBe(0);
});

it('answers a duplicate handle at the field instead of with a 500', function (): void {
    $this->actingAs(User::make()->email('cf-dup@example.com')->makeSuper()->save());

    ($this->feld)(['handle' => 'stimmlage']);

    $this->post(cp_route('leadhub.custom-fields.store'), [
        'handle' => 'stimmlage', 'label' => 'Nochmal', 'type' => 'text',
    ])->assertSessionHasErrors('handle');

    expect(CustomField::query()->count())->toBe(1);
});

/*
 * The write path, which did not exist at all in the first version: definitions
 * could be created and segments could compare on them, and nothing anywhere
 * could ever put a value on a contact. Every custom segment would have stayed
 * empty — quietly, and looking exactly like a segment nobody matches.
 */
it('takes a value through the contact form and casts it on the way in', function (): void {
    $this->actingAs(User::make()->email('cf-write@example.com')->makeSuper()->save());

    ($this->feld)(['handle' => 'chorgroesse', 'type' => CustomField::TYPE_NUMBER]);

    $this->post(cp_route('leadhub.contacts.store'), [
        'email' => 'neu@example.test',
        'custom_fields' => ['chorgroesse' => '45'],
    ])->assertRedirect();

    $kontakt = Contact::query()->where('email', 'neu@example.test')->sole();

    expect($kontakt->custom_fields)->toBe(['chorgroesse' => 45]);

    $this->patch(cp_route('leadhub.contacts.update', $kontakt->id), [
        'email' => 'neu@example.test',
        'custom_fields' => ['chorgroesse' => '120'],
    ])->assertRedirect();

    expect($kontakt->fresh()->custom_fields)->toBe(['chorgroesse' => 120]);
});

it('says nothing on a boolean nobody ever answered', function (): void {
    ($this->feld)(['handle' => 'aktiv', 'type' => CustomField::TYPE_BOOLEAN]);

    $ohne = ($this->kontakt)([]);
    $nein = ($this->kontakt)(['aktiv' => false]);

    $regeln = ['match' => 'all', 'conditions' => [
        ['type' => 'custom', 'field' => 'aktiv', 'operator' => 'is_false'],
    ]];

    $auswerter = app(SegmentEvaluator::class);

    // "Said no" and "said nothing" are different answers. A segment built on
    // the first used to quietly contain the second.
    expect($auswerter->matches($nein->fresh(), $regeln))->toBeTrue()
        ->and($auswerter->matches($ohne->fresh(), $regeln))->toBeFalse();
});

/*
 * The flat-file driver has no table for this, and the segment area must not die
 * on that.
 *
 * The addon's own flat matrix could not see it: TestCase migrates everything
 * regardless of driver, while ServiceProvider registers only the settings
 * migration on flat. A green flat run therefore proves nothing about a table
 * the flat driver deliberately skips — so this test drops it and asks anyway,
 * which is what production does.
 */
it('serves the segment builder on a driver that has no such table', function (): void {
    $this->actingAs(User::make()->email('cf-flat@example.com')->makeSuper()->save());

    config()->set('leadhub.storage.driver', 'flat');
    Schema::drop('leadhub_custom_fields');

    // Before the fix this was a 500 — "no such table" — and it took the whole
    // segment area with it, not just this feature.
    $antwort = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.segments.create'))->assertOk();

    expect(json_decode($antwort->getContent(), true)['props']['vocabulary']['custom_fields'])->toBe([]);
});

it('hides its own screen on a driver that cannot serve it', function (): void {
    $this->actingAs(User::make()->email('cf-flat2@example.com')->makeSuper()->save());

    config()->set('leadhub.storage.driver', 'flat');

    // 404 rather than an error: offering to create something that has nowhere
    // to go is worse than not offering it.
    $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.custom-fields.index'))->assertNotFound();
});
