<?php

use Goldnead\Leadhub\Models\PostalCode;

/*
 * Gegen echte GeoNames-Zeilen, nicht gegen nachgebaute. Der Datensatz hat eine
 * Eigenheit, die eine erfundene Zeile nicht zeigt: dieselbe Postleitzahl steht
 * mehrfach drin, weil grosse Firmen eigene Codes bekommen. 10875 kommt in der
 * Vorlage dreimal vor, mit zwei verschiedenen Punkten.
 *
 * Der Lauf ist offline — heruntergeladen wird nur ohne --file.
 */
function fixturePfad(string $land): string
{
    return __DIR__.'/../__fixtures__/postal-codes/'.$land.'.txt';
}

it('liest eine GeoNames-Datei ein', function () {
    $this->artisan('leadhub:postal-codes', ['countries' => ['DE'], '--file' => fixturePfad('DE')])
        ->assertSuccessful();

    // Fuenf Zeilen, aber nur zwei verschiedene Postleitzahlen.
    expect(PostalCode::query()->where('country', 'DE')->count())->toBe(2);

    $koeln = PostalCode::lookup('50667');

    expect($koeln)->not->toBeNull()
        ->and($koeln->place)->toBe('Köln')
        ->and(round((float) $koeln->latitude, 4))->toBe(50.9387)
        ->and(round((float) $koeln->longitude, 4))->toBe(6.9547);
});

it('legt eine mehrfach genannte Postleitzahl einmal an, in der Mitte ihrer Punkte', function () {
    $this->artisan('leadhub:postal-codes', ['countries' => ['DE'], '--file' => fixturePfad('DE')])
        ->assertSuccessful();

    $rows = PostalCode::query()->where('postal_code', '10875')->get();

    // Ein Mittelwert, keine drei Zeilen und kein willkuerlicher erster Treffer:
    // (48.7794 + 48.7239 + 48.7239) / 3 = 48.7424
    expect($rows)->toHaveCount(1)
        ->and(round((float) $rows->first()->latitude, 4))->toBe(48.7424);
});

it('laeuft zweimal, ohne zu verdoppeln', function () {
    $lauf = fn () => $this->artisan('leadhub:postal-codes', ['countries' => ['DE'], '--file' => fixturePfad('DE')])
        ->assertSuccessful()
        ->run();

    $lauf();
    $lauf();

    expect(PostalCode::query()->where('country', 'DE')->count())->toBe(2);
});

it('haelt Laender auseinander, auch bei gleicher Postleitzahl', function () {
    $this->artisan('leadhub:postal-codes', ['countries' => ['AT'], '--file' => fixturePfad('AT')])
        ->assertSuccessful();

    expect(PostalCode::lookup('1070', 'AT'))->not->toBeNull()
        ->and(PostalCode::lookup('1070', 'DE'))->toBeNull();
});

it('weist einen Laendercode zurueck, der keiner ist', function () {
    $this->artisan('leadhub:postal-codes', ['countries' => ['Deutschland']])
        ->assertFailed();

    expect(PostalCode::query()->count())->toBe(0);
});

it('versteht A-1070 beim Nachschlagen wie 1070', function () {
    $this->artisan('leadhub:postal-codes', ['countries' => ['AT'], '--file' => fixturePfad('AT')])
        ->assertSuccessful();

    // Nur die Schreibweise mit Bindestrich wird als Laenderpraefix erkannt.
    // Ein blindes Abschneiden fuehrender Buchstaben wuerde Andorras AD500 zu
    // 500 machen — deshalb bleibt es bei der dokumentierten Form.
    expect(PostalCode::lookup('A-1070', 'AT'))->not->toBeNull()
        ->and(PostalCode::lookup('a-1070', 'at'))->not->toBeNull()
        ->and(PostalCode::lookup(' 1070 ', 'AT'))->not->toBeNull();
});

it('meldet Zeilen, die zu einem anderen Land gehoeren, statt sie umzuetikettieren', function () {
    // Die DE-Vorlage traegt absichtlich eine oesterreichische Zeile. Sie darf
    // nicht als deutsche Postleitzahl landen — die waere unsichtbar falsch.
    $this->artisan('leadhub:postal-codes', ['countries' => ['DE'], '--file' => fixturePfad('DE')])
        ->expectsOutputToContain('AT')
        ->assertSuccessful();

    expect(PostalCode::lookup('1070', 'DE'))->toBeNull()
        ->and(PostalCode::query()->where('country', 'DE')->count())->toBe(2);
});
