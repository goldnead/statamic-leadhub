<?php

use Symfony\Component\Finder\Finder;

/*
 * Every page a controller can answer with has to exist on the JavaScript side.
 *
 * Found by opening the screen: `CustomFields/Index` was rendered by the
 * controller, the route answered 200, the Inertia payload named the component
 * — and the browser showed a white page, because nothing had registered it.
 *
 * No test could see it. A route test asserts the status; an Inertia test
 * asserts the component NAME. Neither asks whether that name resolves to
 * anything, and the failure is a blank page with the answer only in the
 * console.
 *
 * This compares the two lists directly: what `Inertia::render()` names anywhere
 * in src/, against what `cp.js` registers.
 */
function renderedComponents(): array
{
    $namen = [];

    foreach (Finder::create()->files()->in(__DIR__.'/../../src')->name('*.php') as $datei) {
        preg_match_all("/Inertia::render\(\s*'([^']+)'/", $datei->getContents(), $treffer);
        $namen = array_merge($namen, $treffer[1]);
    }

    return array_values(array_unique($namen));
}

function registeredComponents(): array
{
    $cp = file_get_contents(__DIR__.'/../../resources/js/cp.js');

    preg_match_all("/\\\$inertia\.register\(\s*'([^']+)'/", $cp, $treffer);

    return array_values(array_unique($treffer[1]));
}

it('registers every page a controller can render', function (): void {
    $fehlend = array_values(array_diff(renderedComponents(), registeredComponents()));

    expect($fehlend)->toBe([], 'diese Seiten wuerden als weisse Seite erscheinen');
});

it('renders every page it registers', function (): void {
    // The other direction: a registration whose controller is gone is dead
    // weight in the bundle, and reads as if the screen still exists.
    $ueberzaehlig = array_values(array_diff(registeredComponents(), renderedComponents()));

    expect($ueberzaehlig)->toBe([]);
});
