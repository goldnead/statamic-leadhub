<?php

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\PostalCode;
use Goldnead\Leadhub\Services\PostalCodeRadius;
use Goldnead\Leadhub\Support\SegmentEvaluator;
use Illuminate\Support\Facades\Cache;

/*
 * Echte Koordinaten aus dem GeoNames-Datensatz, nicht erfundene. Die Abstaende
 * darunter sind damit nachrechenbar, und ein Test, der nur gegen selbst
 * gewaehlte Zahlen prueft, beweist ueber eine Entfernungsformel nichts.
 *
 *   50667 Koeln        ->  50996 Koeln-Sued    6,6 km
 *                      ->  53111 Bonn         24,7 km
 *                      ->  40210 Duesseldorf  33,5 km
 */
const ORTE = [
    ['DE', '50667', 'Köln', 50.9387, 6.9547],
    ['DE', '50996', 'Köln', 50.8834, 6.9902],
    ['DE', '53111', 'Bonn', 50.7362, 7.1002],
    ['DE', '40210', 'Düsseldorf', 51.2216, 6.7897],
    ['AT', '1070', 'Wien, Neubau', 48.2085, 16.3721],
    ['CH', '8001', 'Zürich', 47.3721, 8.5417],
];

beforeEach(function (): void {
    Cache::flush();
    app(PostalCodeRadius::class)->forget();

    foreach (ORTE as [$land, $plz, $ort, $lat, $lng]) {
        PostalCode::query()->create([
            'country' => $land,
            'postal_code' => $plz,
            'place' => $ort,
            'latitude' => $lat,
            'longitude' => $lng,
        ]);
    }
});

function kontaktMitPlz(?string $plz, string $land = 'DE'): Contact
{
    $contact = app(ContactRepository::class)->create([
        'email' => strtolower(($plz ?? 'ohne').'-'.$land.'@example.test'),
        'postal_code' => $plz,
        'country' => $land,
    ]);

    return $contact instanceof Contact ? $contact : new Contact((array) $contact);
}

function imUmkreis(string $plz, float $km, array $extra = []): array
{
    return ['match' => 'all', 'conditions' => [
        array_merge(['type' => 'geo', 'operator' => 'within_km', 'plz' => $plz, 'value' => $km], $extra),
    ]];
}

it('nimmt auf, was im Radius liegt, und laesst draussen, was nicht', function () {
    $evaluator = app(SegmentEvaluator::class);
    $regel = imUmkreis('50667', 30);

    // 6,6 km und 24,7 km liegen drin, 33,5 km nicht. Der Radius trennt also
    // zwischen Bonn und Duesseldorf — genau dort, wo er soll.
    expect($evaluator->matches(kontaktMitPlz('50996'), $regel))->toBeTrue()
        ->and($evaluator->matches(kontaktMitPlz('53111'), $regel))->toBeTrue()
        ->and($evaluator->matches(kontaktMitPlz('40210'), $regel))->toBeFalse();
});

it('nimmt Duesseldorf auf, sobald der Radius weit genug ist', function () {
    $evaluator = app(SegmentEvaluator::class);

    expect($evaluator->matches(kontaktMitPlz('40210'), imUmkreis('50667', 35)))->toBeTrue();
});

it('zaehlt den Mittelpunkt selbst mit', function () {
    $evaluator = app(SegmentEvaluator::class);

    expect($evaluator->matches(kontaktMitPlz('50667'), imUmkreis('50667', 5)))->toBeTrue();
});

it('laesst einen Kontakt ohne Postleitzahl bei beiden Operatoren durchfallen', function () {
    $evaluator = app(SegmentEvaluator::class);
    $ohne = kontaktMitPlz(null);

    $drinnen = imUmkreis('50667', 30);
    $draussen = imUmkreis('50667', 30, ['operator' => 'outside_km']);

    // „Wir wissen nicht, wo sie sind" ist nicht „sie sind weit weg". Ein leeres
    // Feld darf in keine der beiden Richtungen stillschweigend treffen.
    expect($evaluator->matches($ohne, $drinnen))->toBeFalse()
        ->and($evaluator->matches($ohne, $draussen))->toBeFalse();
});

it('kehrt die Auswahl bei outside_km um', function () {
    $evaluator = app(SegmentEvaluator::class);
    $regel = imUmkreis('50667', 30, ['operator' => 'outside_km']);

    expect($evaluator->matches(kontaktMitPlz('40210'), $regel))->toBeTrue()
        ->and($evaluator->matches(kontaktMitPlz('53111'), $regel))->toBeFalse();
});

it('haelt Laender auseinander', function () {
    $evaluator = app(SegmentEvaluator::class);

    // Wien und Zuerich liegen rund 600 km auseinander; ein Radius von 50 km um
    // Wien darf Zuerich nicht treffen, auch nicht ueber eine gleich aussehende
    // Postleitzahl.
    $umWien = imUmkreis('1070', 50, ['country' => 'AT']);

    expect($evaluator->matches(kontaktMitPlz('1070', 'AT'), $umWien))->toBeTrue()
        ->and($evaluator->matches(kontaktMitPlz('8001', 'CH'), $umWien))->toBeFalse();
});

it('versteht A-1070 so wie 1070', function () {
    $evaluator = app(SegmentEvaluator::class);

    // Die Schreibweise mit Laenderpraefix steht so in Adrians Notion-Daten.
    expect($evaluator->matches(kontaktMitPlz('A-1070', 'AT'), imUmkreis('A-1070', 20, ['country' => 'AT'])))
        ->toBeTrue();
});

it('trifft niemanden, wenn der Mittelpunkt gar nicht existiert', function () {
    $evaluator = app(SegmentEvaluator::class);

    // Ein Tippfehler im Segment darf nicht die ganze Liste anschreiben.
    expect($evaluator->matches(kontaktMitPlz('50996'), imUmkreis('99999', 500)))->toBeFalse();
});

it('trifft niemanden bei Radius null', function () {
    $evaluator = app(SegmentEvaluator::class);

    expect($evaluator->matches(kontaktMitPlz('50667'), imUmkreis('50667', 0)))->toBeFalse();
});

it('gibt die Postleitzahlen im Umkreis als Menge zurueck', function () {
    $menge = app(PostalCodeRadius::class)->within('50667', 'DE', 30);

    expect($menge)->toHaveKey('DE:50667')
        ->toHaveKey('DE:50996')
        ->toHaveKey('DE:53111')
        ->not->toHaveKey('DE:40210')
        ->not->toHaveKey('AT:1070');
});
