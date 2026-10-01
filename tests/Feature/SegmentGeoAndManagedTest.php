<?php

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\SegmentRepository;
use Goldnead\Leadhub\Models\PostalCode;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileContactRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileSegmentRepository;
use Goldnead\Leadhub\Services\PostalCodeRadius;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\User;

/*
 * Paket 3 der Kampagnenserie: PLZ-Segmente sichtbar.
 *
 * - `managed_by` am Segment: wer es angelegt hat und dessen Abgleich es
 *   ueberschreibt (statamic-marketing fuer Serien-Segmente).
 * - Die `geo`-Bedingung im Editor: Vokabular, Live-Zahl, aufgeloeste Orte.
 * - PLZ und Land am Kontakt: sichtbar und bearbeitbar.
 *
 * Beide Treiber: das Feld muss im YAML genauso ueberleben wie in der Spalte.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        $path = (string) config('leadhub.storage.flat.path');
        if ($path && is_dir($path)) {
            File::deleteDirectory($path);
        }
        try {
            Storage::disk((string) config('leadhub.storage.flat.index_disk', 'local'))
                ->deleteDirectory((string) config('leadhub.storage.flat.index_path', 'leadhub/index'));
        } catch (Throwable) {
        }
        foreach ([
            FlatFileContactRepository::class,
            FlatFileSegmentRepository::class,
            'leadhub.index.contacts',
        ] as $abstract) {
            app()->forgetInstance($abstract);
        }
    }

    Cache::flush();
    app(PostalCodeRadius::class)->forget();

    foreach ([
        ['DE', '50667', 'Köln', 50.9387, 6.9547],
        ['DE', '53111', 'Bonn', 50.7362, 7.1002],
        ['DE', '40210', 'Düsseldorf', 51.2216, 6.7897],
    ] as [$land, $plz, $ort, $lat, $lng]) {
        PostalCode::query()->create([
            'country' => $land, 'postal_code' => $plz, 'place' => $ort,
            'latitude' => $lat, 'longitude' => $lng,
        ]);
    }

    $this->user = User::make()->email('geo-admin@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);
});

function serienVerwaltung(): array
{
    return [
        'source' => 'statamic-marketing',
        'label' => 'Serie: Konzerte Herbst',
        'url' => 'https://example.test/cp/marketing/campaigns/herbst',
    ];
}

function segmentGeoProps($response): array
{
    return json_decode($response->getContent(), true)['props'] ?? [];
}

// ── managed_by an der Ablage ────────────────────────────────────────────────

it('speichert managed_by beim Anlegen und liest es zurueck', function (): void {
    $repo = app(SegmentRepository::class);

    $repo->create([
        'name' => 'Konzert: Köln 50667 (30 km)',
        'handle' => 'series-herbst-1',
        'rules' => ['match' => 'all', 'conditions' => []],
        'managed_by' => serienVerwaltung(),
    ]);

    $segment = $repo->findByHandle('series-herbst-1');

    expect($segment->managedBy())->toBe(serienVerwaltung())
        ->and($segment->isManaged())->toBeTrue();
});

it('setzt und loescht managed_by ueber update', function (): void {
    $repo = app(SegmentRepository::class);
    $segment = $repo->create(['name' => 'Spaeter verwaltet', 'rules' => ['match' => 'all', 'conditions' => []]]);

    expect($segment->managedBy())->toBeNull();

    $repo->update($segment, ['managed_by' => serienVerwaltung()]);
    expect($repo->findByHandle('spaeter-verwaltet')->managedBy())->toBe(serienVerwaltung());

    $repo->update($repo->findByHandle('spaeter-verwaltet'), ['managed_by' => null]);
    expect($repo->findByHandle('spaeter-verwaltet')->isManaged())->toBeFalse();
});

it('verwirft ein managed_by ohne Quelle', function (): void {
    $repo = app(SegmentRepository::class);
    $repo->create([
        'name' => 'Kaputt', 'rules' => [],
        'managed_by' => ['label' => 'ohne Quelle'],
    ]);

    expect($repo->findByHandle('kaputt')->managedBy())->toBeNull();
});

// ── CP: Liste und Editor ────────────────────────────────────────────────────

it('zeigt managed_by und die Mitgliederzahl in der Segmentliste', function (): void {
    app(SegmentRepository::class)->create([
        'name' => 'Verwaltet', 'handle' => 'series-x', 'rules' => [],
        'managed_by' => serienVerwaltung(),
    ]);
    app(SegmentRepository::class)->create(['name' => 'Frei', 'rules' => []]);

    $rows = collect(segmentGeoProps(
        $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.segments.index'))
    )['segments'])->keyBy('handle');

    expect($rows['series-x']['managed_by'])->toBe(serienVerwaltung())
        ->and($rows['series-x'])->toHaveKey('members_count')
        ->and($rows['frei']['managed_by'])->toBeNull();
});

it('gibt dem Editor die geo-Bedingung mit Operatoren und Laendern', function (): void {
    $props = segmentGeoProps(
        $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.segments.create'))
    );

    expect($props['vocabulary']['geo_operators'])->toBe(['within_km', 'outside_km'])
        ->and($props['vocabulary']['countries'])->toBe(['DE', 'AT', 'CH']);
});

it('schreibt Name und Regel eines verwalteten Segments im CP nicht um', function (): void {
    $repo = app(SegmentRepository::class);
    $rules = ['match' => 'all', 'conditions' => [
        ['type' => 'geo', 'operator' => 'within_km', 'plz' => '50667', 'value' => 30, 'country' => 'DE'],
    ]];
    $segment = $repo->create([
        'name' => 'Konzert: Köln', 'handle' => 'series-koeln', 'rules' => $rules,
        'managed_by' => serienVerwaltung(),
    ]);

    $this->withHeaders(['X-Inertia' => 'true'])
        ->patch(cp_route('leadhub.segments.update', $segment->uuid), [
            'name' => 'Umbenannt',
            'description' => 'Notiz fuer das Team',
            'is_active' => true,
            'rules' => ['match' => 'any', 'conditions' => []],
        ]);

    $fresh = $repo->findByHandle('series-koeln');

    expect($fresh->name)->toBe('Konzert: Köln')
        ->and((array) $fresh->rules)->toBe($rules)
        ->and($fresh->description)->toBe('Notiz fuer das Team')
        ->and($fresh->managedBy())->toBe(serienVerwaltung());
});

it('laesst managed_by nicht ueber das CP-Formular setzen', function (): void {
    $this->withHeaders(['X-Inertia' => 'true'])
        ->post(cp_route('leadhub.segments.store'), [
            'name' => 'Eingeschmuggelt',
            'rules' => ['match' => 'all', 'conditions' => []],
            'managed_by' => serienVerwaltung(),
        ]);

    expect(app(SegmentRepository::class)->findByHandle('eingeschmuggelt')->isManaged())->toBeFalse();
});

it('reicht managed_by und die Regel an die Bearbeiten-Seite', function (): void {
    $segment = app(SegmentRepository::class)->create([
        'name' => 'Konzert: Bonn', 'handle' => 'series-bonn', 'rules' => [],
        'managed_by' => serienVerwaltung(),
    ]);

    $props = segmentGeoProps(
        $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.segments.edit', $segment->uuid))
    );

    expect($props['segment']['managed_by'])->toBe(serienVerwaltung());
});

// ── Live-Zahl der geo-Bedingung ─────────────────────────────────────────────

it('zaehlt geo-Treffer live, nennt den Ort und die Kontakte ohne PLZ', function (): void {
    $contacts = app(ContactRepository::class);
    foreach (['53111' => 'bonn', '40210' => 'dus', '' => 'ohne'] as $plz => $name) {
        $contacts->create([
            'email' => $name.'@example.test', 'email_normalized' => $name.'@example.test',
            'postal_code' => $plz === '' ? null : (string) $plz, 'country' => 'DE',
        ]);
    }

    $response = $this->get(cp_route('leadhub.segments.preview').'?'.http_build_query([
        'rules' => ['match' => 'all', 'conditions' => [
            ['type' => 'geo', 'operator' => 'within_km', 'plz' => '50667', 'value' => 30, 'country' => 'DE'],
            ['type' => 'geo', 'operator' => 'within_km', 'plz' => '99999', 'value' => 30, 'country' => 'DE'],
        ]],
    ]));

    $response->assertOk();
    expect($response->json('places'))->toBe(['DE:50667' => 'Köln', 'DE:99999' => null])
        ->and($response->json('without_postal_code'))->toBe(1);

    // Nur die erste Bedingung: Bonn (24,7 km) ja, Duesseldorf (33,5 km) nein.
    $nur = $this->get(cp_route('leadhub.segments.preview').'?'.http_build_query([
        'rules' => ['match' => 'all', 'conditions' => [
            ['type' => 'geo', 'operator' => 'within_km', 'plz' => '50667', 'value' => 30, 'country' => 'DE'],
        ]],
    ]));
    expect($nur->json('count'))->toBe(1);
});

it('meldet ohne geo-Bedingung keine Orte und keine PLZ-Luecke', function (): void {
    $response = $this->get(cp_route('leadhub.segments.preview').'?'.http_build_query([
        'rules' => ['match' => 'all', 'conditions' => [['type' => 'tag', 'operator' => 'has', 'value' => 'vip']]],
    ]));

    expect($response->json('places'))->toBe([])
        ->and($response->json('without_postal_code'))->toBeNull();
});

// ── PLZ und Land am Kontakt ─────────────────────────────────────────────────

it('zeigt PLZ, Land und Ort im Kontakt-Detail', function (): void {
    $contact = app(ContactRepository::class)->create([
        'email' => 'ort@example.test', 'email_normalized' => 'ort@example.test',
        'postal_code' => '53111', 'country' => 'DE',
    ]);

    $props = segmentGeoProps(
        $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.contacts.show', $contact->uuid))
    );

    expect($props['contact']['postal_code'])->toBe('53111')
        ->and($props['contact']['country'])->toBe('DE')
        ->and($props['contact']['place'])->toBe('Bonn')
        ->and($props['countries'])->toBe(['DE', 'AT', 'CH']);
});

it('speichert PLZ und Land aus dem Kontakt-Detail, normalisiert', function (): void {
    $contact = app(ContactRepository::class)->create([
        'email' => 'umzug@example.test', 'email_normalized' => 'umzug@example.test',
    ]);

    $this->withHeaders(['X-Inertia' => 'true'])
        ->patch(cp_route('leadhub.contacts.update', $contact->uuid), [
            'postal_code' => ' 50 667 ',
            'country' => 'de',
        ]);

    $fresh = app(ContactRepository::class)->find($contact->uuid);

    expect($fresh->postal_code)->toBe('50667')
        ->and($fresh->country)->toBe('DE');
});

it('lehnt ein Land ab, das kein Zwei-Buchstaben-Code ist', function (): void {
    $contact = app(ContactRepository::class)->create([
        'email' => 'land@example.test', 'email_normalized' => 'land@example.test',
    ]);

    $this->patch(cp_route('leadhub.contacts.update', $contact->uuid), ['country' => 'Deutschland'])
        ->assertSessionHasErrors('country');
});
