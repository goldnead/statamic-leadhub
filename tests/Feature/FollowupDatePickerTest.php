<?php

use Goldnead\Leadhub\Models\Contact;
use Statamic\Facades\User;

/**
 * The follow-up form, exercised through the real request path.
 *
 * The CP <DatePicker> v-model is an @internationalized/date DateValue object,
 * not a string. Posted as-is, `due_at` arrives as an array and Laravel's `date`
 * rule answers "Not a valid date." — a 422 the contact page never displayed, so
 * follow-ups simply could not be created from the UI.
 *
 * These tests hit POST /cp/leadhub/contacts/{contact}/followup, not the model
 * or the service: the defect lived entirely between the browser and validation.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('Follow-up CP route assertions target the eloquent driver.');
    }

    $this->user = User::make()->email('followup-cp@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    $this->contact = Contact::create(['email' => 'followup-target@example.com']);
    $this->url = cp_route('leadhub.contacts.followup.store', $this->contact->uuid);
});

/** Exactly what the CP date picker serializes for 19 July 2026, 14:30. */
function datePickerValue(): array
{
    return [
        'calendar' => ['identifier' => 'gregory'],
        'era' => 'AD',
        'year' => 2026,
        'month' => 7,
        'day' => 19,
        'hour' => 14,
        'minute' => 30,
        'second' => 0,
    ];
}

it('creates a follow-up from the payload the CP date picker actually sends', function (): void {
    $response = $this->postJson($this->url, [
        'due_at' => datePickerValue(),
        'note' => 'From the date picker',
    ]);

    // Without the normalization this is a 422 "Not a valid date."
    $response->assertStatus(302);

    $followup = $this->contact->followups()->first();

    expect($followup)->not->toBeNull()
        ->and($followup->due_at->format('Y-m-d H:i'))->toBe('2026-07-19 14:30')
        ->and($followup->note)->toBe('From the date picker');
});

it('accepts a date-only picker value (no time granularity)', function (): void {
    $value = datePickerValue();
    unset($value['hour'], $value['minute'], $value['second']);

    $this->postJson($this->url, ['due_at' => $value])->assertStatus(302);

    expect($this->contact->followups()->first()->due_at->format('Y-m-d H:i'))->toBe('2026-07-19 00:00');
});

it('still accepts a plain datetime string — the control group', function (): void {
    $this->postJson($this->url, ['due_at' => '2026-07-19 14:30:00'])->assertStatus(302);

    expect($this->contact->followups()->first()->due_at->format('Y-m-d H:i'))->toBe('2026-07-19 14:30');
});

it('still rejects a payload that is genuinely not a date, with a field error', function (): void {
    $response = $this->postJson($this->url, ['due_at' => ['nonsense' => true]]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('due_at');

    expect($this->contact->followups()->count())->toBe(0);
});

it('reports the note-length error on the field so the page can show it', function (): void {
    $response = $this->postJson($this->url, [
        'due_at' => datePickerValue(),
        'note' => str_repeat('x', 5001),
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('note');
});

it('normalizes the same payload on the follow-up update route', function (): void {
    $this->postJson($this->url, ['due_at' => '2026-07-19 09:00:00'])->assertStatus(302);
    $followup = $this->contact->followups()->first();

    $value = datePickerValue();
    $value['day'] = 20;

    $this->patchJson(cp_route('leadhub.followups.update', $followup->uuid), [
        'due_at' => $value,
    ])->assertStatus(302);

    expect($followup->fresh()->due_at->format('Y-m-d H:i'))->toBe('2026-07-20 14:30');
});
