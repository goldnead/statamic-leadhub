<?php

use Goldnead\Leadhub\Integrations\Entitlements\AccessGranter;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Tests\Fixtures\Timeline\RecordingGranter;
use Statamic\Facades\Role;
use Statamic\Facades\User;

require_once __DIR__.'/../Fixtures/Timeline/NeighbourStubs.php';

/**
 * "Grant access" from the contact screen.
 *
 * Its own permission, because it writes into a neighbour: a user who may read
 * every contact must not be able to open a paid course for one. Without the
 * permission the answer is 403 whatever is installed; with it and without
 * entitlements, 404 — the same answer as the button they never saw.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('The contact screen props target the eloquent driver.');
    }

    $this->contact = Contact::create(['email' => 'Kundin@Beispiel.de']);
    $this->url = cp_route('leadhub.contacts.grant-access', $this->contact->uuid);

    $this->products = [
        ['value' => 'kurs', 'label' => 'Frühlingskurs', 'slugs' => ['kurs-fruehling', 'begleit-cd']],
        ['value' => 'buch', 'label' => 'Arbeitsbuch', 'slugs' => ['buch']],
    ];

    $this->super = function () {
        $user = User::make()->email('super@example.com')->makeSuper();
        $user->save();

        return $user;
    };

    $this->withOnly = function (array $permissions) {
        $role = Role::make('grant-test-'.uniqid())->permissions($permissions);
        $role->save();
        $user = User::make()->email(uniqid().'@example.com')->assignRole($role->handle());
        $user->save();

        return $user;
    };
});

it('answers 403 without the permission, even when entitlements is installed', function (): void {
    app()->instance(AccessGranter::class, new RecordingGranter($this->products));

    $this->actingAs(($this->withOnly)(['view leadhub', 'view leadhub contacts', 'edit leadhub contacts']))
        ->post($this->url, ['product' => 'kurs'])
        ->assertStatus(403);
});

it('answers 404 with the permission when entitlements is not installed', function (): void {
    expect(app(AccessGranter::class)->available())->toBeFalse();

    $this->actingAs(($this->super)())
        ->post($this->url, ['product' => 'kurs'])
        ->assertStatus(404);
});

it('hides the action from the contact screen without the permission or the neighbour', function (): void {
    $props = fn () => json_decode(
        $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('leadhub.contacts.show', $this->contact->uuid))
            ->assertStatus(200)
            ->getContent(),
        true,
    )['props'];

    $this->actingAs(($this->super)());
    expect($props()['accessGrant'])->toBeNull();

    app()->instance(AccessGranter::class, new RecordingGranter($this->products));
    expect($props()['accessGrant']['url'])->toBe($this->url)
        ->and($props()['accessGrant']['products'])->toBe([
            ['value' => 'kurs', 'label' => 'Frühlingskurs'],
            ['value' => 'buch', 'label' => 'Arbeitsbuch'],
        ]);

    $this->actingAs(($this->withOnly)(['view leadhub', 'view leadhub contacts']));
    expect($props()['accessGrant'])->toBeNull();
});

it('grants every slug the product carries, through the facade, and writes the audit event', function (): void {
    $granter = new RecordingGranter($this->products);
    app()->instance(AccessGranter::class, $granter);
    $user = ($this->super)();

    $this->actingAs($user)
        ->from(cp_route('leadhub.contacts.show', $this->contact->uuid))
        ->post($this->url, ['product' => 'kurs', 'note' => 'Reklamation vom 3.9.'])
        ->assertRedirect(cp_route('leadhub.contacts.show', $this->contact->uuid))
        ->assertSessionHas('success');

    expect($granter->writes)->toHaveCount(2)
        ->and(array_column($granter->writes, 'slug'))->toBe(['kurs-fruehling', 'begleit-cd'])
        // The subject is the family's convention: ('email', normalized address).
        ->and($granter->writes[0]['subject'])->toBe(['email', 'kundin@beispiel.de'])
        ->and($granter->writes[0]['sourceRef'])->toBe('leadhub:'.$this->contact->uuid)
        ->and($granter->writes[0]['meta']['note'])->toBe('Reklamation vom 3.9.')
        ->and($granter->writes[0]['meta']['product'])->toBe('kurs')
        ->and($granter->writes[0]['meta']['granted_by'])->toBe((string) $user->getAuthIdentifier())
        ->and($granter->writes[0]['actor'])->toBe((string) $user->getAuthIdentifier());

    $event = Event::query()->where('contact_id', $this->contact->id)->where('type', Event::TYPE_ACCESS_GRANTED)->first();

    expect($event)->not->toBeNull()
        ->and($event->summary)->toContain('Frühlingskurs')
        ->and($event->actor_type)->toBe('user')
        ->and($event->payload['slugs'])->toBe(['kurs-fruehling', 'begleit-cd'])
        ->and($event->payload['entitlement_ids'])->toBe([1, 2])
        ->and($event->payload['detail'][0]['value'])->toBe('Reklamation vom 3.9.');
});

it('refuses when the contact holds a revoked grant, and writes nothing', function (): void {
    // The facade would hand the revoked grant back untouched — a retried
    // webhook must not undo a refund. From this button that silence would be
    // a lie, so the case is refused with a pointer to entitlements' restore.
    $granter = new RecordingGranter($this->products, revoked: ['begleit-cd']);
    app()->instance(AccessGranter::class, $granter);

    $this->actingAs(($this->super)())
        ->from(cp_route('leadhub.contacts.show', $this->contact->uuid))
        ->post($this->url, ['product' => 'kurs'])
        ->assertRedirect()
        ->assertSessionHasErrors('product');

    expect(session('errors')->first('product'))->toContain('begleit-cd')
        ->and($granter->writes)->toBe([])
        ->and(Event::query()->where('contact_id', $this->contact->id)->where('type', Event::TYPE_ACCESS_GRANTED)->exists())->toBeFalse();
});

it('says so when every slug was already granted, and records no event', function (): void {
    $granter = new RecordingGranter($this->products, existing: ['buch']);
    app()->instance(AccessGranter::class, $granter);

    $this->actingAs(($this->super)())
        ->from(cp_route('leadhub.contacts.show', $this->contact->uuid))
        ->post($this->url, ['product' => 'buch'])
        ->assertRedirect()
        ->assertSessionHas('success', fn (string $flash) => str_contains($flash, 'already') || str_contains($flash, 'bereits'));

    expect($granter->writes)->toHaveCount(1)
        ->and(Event::query()->where('contact_id', $this->contact->id)->where('type', Event::TYPE_ACCESS_GRANTED)->exists())->toBeFalse();
});

it('records only the slugs that were new when a bundle is half granted already', function (): void {
    $granter = new RecordingGranter($this->products, existing: ['kurs-fruehling']);
    app()->instance(AccessGranter::class, $granter);

    $this->actingAs(($this->super)())
        ->post($this->url, ['product' => 'kurs'])
        ->assertRedirect();

    $event = Event::query()->where('contact_id', $this->contact->id)->where('type', Event::TYPE_ACCESS_GRANTED)->first();

    expect($event->payload['slugs'])->toBe(['begleit-cd'])
        ->and($event->payload['already_granted'])->toBe(['kurs-fruehling']);
});

it('refuses a product the catalogue does not know', function (): void {
    $granter = new RecordingGranter($this->products);
    app()->instance(AccessGranter::class, $granter);

    $this->actingAs(($this->super)())
        ->from(cp_route('leadhub.contacts.show', $this->contact->uuid))
        ->post($this->url, ['product' => 'nicht-da'])
        ->assertRedirect()
        ->assertSessionHasErrors('product');

    expect($granter->writes)->toBe([]);
});

it('refuses to grant to a contact without an address', function (): void {
    $granter = new RecordingGranter($this->products);
    app()->instance(AccessGranter::class, $granter);
    $contact = Contact::create(['first_name' => 'Ohne', 'last_name' => 'Adresse']);

    $this->actingAs(($this->super)())
        ->from(cp_route('leadhub.contacts.show', $contact->uuid))
        ->post(cp_route('leadhub.contacts.grant-access', $contact->uuid), ['product' => 'buch'])
        ->assertRedirect()
        ->assertSessionHasErrors('product');

    expect($granter->writes)->toBe([]);
});

it('offers a product once per handle and a slug list even without payments', function (): void {
    $granter = new class extends AccessGranter
    {
        protected function catalogue(): array
        {
            return [
                'kurs' => ['name' => 'Frühlingskurs', 'grants' => 'kurs-fruehling, begleit-cd'],
                'buch' => ['name' => 'Arbeitsbuch'],
                '' => ['name' => 'kaputt'],
            ];
        }
    };

    expect($granter->options())->toBe([
        ['value' => 'buch', 'label' => 'Arbeitsbuch', 'slugs' => ['buch']],
        ['value' => 'kurs', 'label' => 'Frühlingskurs', 'slugs' => ['kurs-fruehling', 'begleit-cd']],
    ])->and($granter->slugsFor('kurs'))->toBe(['kurs-fruehling', 'begleit-cd'])
        ->and($granter->slugsFor('nicht-da'))->toBe([]);
});
