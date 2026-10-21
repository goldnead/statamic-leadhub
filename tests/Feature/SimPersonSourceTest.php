<?php

use Goldnead\BrandContext\Simulation\PersonSources;
use Goldnead\Leadhub\Integrations\SimPersonSource;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/*
 * The CRM as a person source for the dry run's "as it is for person X". Read only: a search, an identify and a facet
 * write nothing and log nothing, and the permission of the signed-in user decides.
 */
beforeEach(function (): void {
    if (! class_exists(PersonSources::class)) {
        test()->markTestSkipped('Needs statamic-brand-context with the person sources (Simulation\PersonSources).');
    }

    $this->source = new SimPersonSource;

    $this->lena = Contact::create([
        'email' => 'Lena.Probe@Example.test',
        'first_name' => 'Lena',
        'last_name' => 'Probe',
        'full_name' => 'Lena Probe',
        'status' => 'customer',
        'do_not_contact' => true,
        'custom_fields' => ['kurs' => 'Stimme', 'anzahl' => 3, 'verschachtelt' => ['a' => 1], 'leer' => null],
    ]);

    foreach (['Chorleitung', 'Stimme'] as $name) {
        $tag = Tag::create(['name' => $name]);
        $this->lena->tags()->attach($tag->id);
    }

    $this->withOnly = function (array $permissions) {
        $role = Role::make('sim-person-'.uniqid())->permissions($permissions);
        $role->save();
        $user = User::make()->email(uniqid().'@example.com')->assignRole($role->handle());
        $user->save();

        return $user;
    };

    $this->reader = ($this->withOnly)(['view leadhub contacts']);
});

it('finds a person by a part of her address', function (): void {
    expect($this->source->searchPersons('probe@example', 8))->toBe([
        ['key' => (string) $this->lena->id, 'label' => 'Lena Probe (Lena.Probe@Example.test)'],
    ]);
});

it('finds a person by a part of her name', function (): void {
    expect(array_column($this->source->searchPersons('Probe', 8), 'key'))->toBe([(string) $this->lena->id])
        ->and(array_column($this->source->searchPersons('Lena', 8), 'key'))->toBe([(string) $this->lena->id]);
});

it('answers nothing for an empty search, and never lists everyone', function (): void {
    Contact::create(['email' => 'andere@example.test', 'first_name' => 'Anders']);

    expect($this->source->searchPersons('', 8))->toBe([])
        ->and($this->source->searchPersons('   ', 8))->toBe([])
        ->and($this->source->searchPersons('%', 8))->toBe([])
        ->and($this->source->searchPersons('_', 8))->toBe([]);
});

it('stops at the limit and never gives more than ten', function (): void {
    for ($i = 1; $i <= 12; $i++) {
        Contact::create(['email' => "limit{$i}@example.test"]);
    }

    expect($this->source->searchPersons('limit', 3))->toHaveCount(3)
        ->and($this->source->searchPersons('limit', 50))->toHaveCount(10)
        ->and($this->source->searchPersons('limit', 0))->toBe([]);
});

it('leaves archived contacts out of the search', function (): void {
    Contact::create(['email' => 'archiv@example.test', 'archived_at' => now()]);

    expect($this->source->searchPersons('archiv', 8))->toBe([]);
});

it('identifies a contact by a positive whole id, and by nothing else', function (): void {
    expect($this->source->identifyPerson((string) $this->lena->id))->toBe([
        'email' => 'lena.probe@example.test',
        'id' => $this->lena->id,
    ])
        ->and($this->source->identifyPerson((string) ($this->lena->id + 1000)))->toBeNull()
        ->and($this->source->identifyPerson('abc'))->toBeNull()
        ->and($this->source->identifyPerson('-1'))->toBeNull()
        ->and($this->source->identifyPerson('0'))->toBeNull()
        ->and($this->source->identifyPerson('1.5'))->toBeNull()
        ->and($this->source->identifyPerson(''))->toBeNull();
});

it('gives the traits, the status, the name, the address and the scalar custom fields', function (): void {
    $facet = $this->source->personFacet($this->source->identifyPerson((string) $this->lena->id));

    expect($facet['traits'])->toMatchArray([
        'contact.tags' => ['Chorleitung', 'Stimme'],
        'contact.status' => 'customer',
        'contact.first_name' => 'Lena',
        'contact.last_name' => 'Probe',
        'contact.name' => 'Lena Probe',
        'contact.email' => 'Lena.Probe@Example.test',
        'contact.custom.kurs' => 'Stimme',
        'contact.custom.anzahl' => 3,
    ])
        ->and($facet['traits'])->not->toHaveKey('contact.custom.verschachtelt')
        ->and($facet['traits'])->not->toHaveKey('contact.custom.leer')
        ->and($facet['unsubscribed'])->toBeTrue();
});

it('leaves out what is null, and says she is not unsubscribed when she is not', function (): void {
    $plain = Contact::create(['email' => 'plain@example.test', 'first_name' => null, 'last_name' => null, 'full_name' => null]);

    $facet = $this->source->personFacet(['id' => $plain->id, 'email' => 'plain@example.test']);

    expect($facet['traits'])->toHaveKey('contact.tags', [])
        ->and($facet['traits'])->not->toHaveKey('contact.name')
        ->and($facet['traits'])->not->toHaveKey('contact.first_name')
        ->and($facet['unsubscribed'])->toBeFalse();
});

it('gives nothing for an identity whose address is not the one of that contact', function (): void {
    expect($this->source->personFacet(['id' => $this->lena->id, 'email' => 'jemand@example.test']))->toBe([])
        ->and($this->source->personFacet(['id' => 999999, 'email' => 'lena.probe@example.test']))->toBe([]);
});

it('puts the person together through the sources, with the unsubscribed marker', function (): void {
    $person = app(PersonSources::class)->state('leadhub|'.$this->lena->id, $this->reader);

    expect($person->isKnown())->toBeTrue()
        ->and($person->trait('contact.tags'))->toBe(['Chorleitung', 'Stimme'])
        ->and($person->trait('contact.status'))->toBe('customer')
        ->and($person->trait('marketing.unsubscribed'))->toBeTrue();
});

it('is open to the users with the right and to no one else', function (): void {
    expect($this->source->personsAuthorized(null))->toBeFalse()
        ->and($this->source->personsAuthorized(($this->withOnly)(['view leadhub'])))->toBeFalse()
        ->and($this->source->personsAuthorized($this->reader))->toBeTrue();

    expect(app(PersonSources::class)->search('Lena', ($this->withOnly)(['view leadhub'])))->toBe([]);
});

it('writes nothing: no statement that is not a select or a pragma', function (): void {
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (! preg_match('/^\s*(select|pragma)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $this->source->searchPersons('Lena', 8);
    $identity = $this->source->identifyPerson((string) $this->lena->id);
    $this->source->personFacet($identity);
    app(PersonSources::class)->state('leadhub|'.$this->lena->id, $this->reader);

    expect($writes)->toBe([]);
});

it('logs nothing, so no address or name can end up in a log', function (): void {
    $logged = [];
    Log::listen(function ($message) use (&$logged): void {
        $logged[] = $message->message;
    });

    $this->source->searchPersons('Lena', 8);
    $this->source->personFacet($this->source->identifyPerson((string) $this->lena->id));
    app(PersonSources::class)->state('leadhub|'.$this->lena->id, $this->reader);

    expect($logged)->toBe([]);
});
