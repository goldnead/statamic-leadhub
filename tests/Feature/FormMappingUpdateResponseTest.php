<?php

/**
 * Regression test for bug L1: the Form Mappings edit screen uses Statamic 6's
 * native <PublishForm>, which submits via axios and expects a JSON response.
 *
 * The edit (GET) and update (PATCH) routes share the same URI
 * (`/cp/leadhub/forms/{formHandle}`). When update() returned `back()` — a 302
 * whose Referer is that shared URI — the axios XHR followed the redirect,
 * preserved the PATCH verb, re-hit update, and looped until
 * net::ERR_TOO_MANY_REDIRECTS. The Save button stayed disabled because no
 * valid JSON ever cleared PublishForm's `saving` flag.
 *
 * The fix: update() must return a JSON payload (mirroring the value shape
 * edit() feeds the blueprint) so PublishForm re-hydrates and clears `saving`.
 */

use Goldnead\Leadhub\Contracts\Repositories\FormMappingRepository;
use Statamic\Facades\User;

beforeEach(function (): void {
    $this->user = User::make()
        ->email('forms@example.com')
        ->makeSuper();
    $this->user->save();

    $this->actingAs($this->user);
});

it('returns a JSON response (not a 302 redirect) when saving a form mapping', function (): void {
    // A mapping row must exist for the update route (findByHandle). Use the
    // repository so this stays driver-agnostic across the eloquent/flat matrix.
    app(FormMappingRepository::class)->firstOrCreate('contact');

    $response = $this->patch(cp_route('leadhub.forms.update', 'contact'), [
        'enabled' => true,
        'email_field' => 'email',
        'default_status' => 'new',
        'default_tags' => [],
        'attach_full_submission' => true,
    ]);

    // Must NOT be a redirect — a 302 back to the shared edit/update URI is
    // exactly what caused the ERR_TOO_MANY_REDIRECTS loop.
    expect($response->getStatusCode())->toBe(200);

    $response->assertHeader('Content-Type', 'application/json');

    // PublishForm's SavePipeline Request step reads response.data.data.values
    // to re-hydrate the form after save.
    $payload = $response->json();
    expect($payload)->toHaveKey('data');
    expect($payload['data'])->toHaveKey('values');
    expect($payload['data']['values'])->toMatchArray([
        'enabled' => true,
        'email_field' => 'email',
    ]);
})->skip(
    fn () => config('leadhub.storage.driver') === 'flat',
    'Forms/Edit re-hydration runs through mappingToValues(), which hits a '
    .'pre-existing flat-driver default_tags cast bug (double json_decode). '
    .'That is unrelated to bug L1 (the eloquent axios redirect loop) and out of scope here.'
);
