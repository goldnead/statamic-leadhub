<?php

/**
 * English and German must describe the same interface.
 *
 * The addon ships both locales, and Laravel falls back key by key: a key missing
 * from `resources/lang/de/` does not show up as a gap, it shows up as a single
 * English label in an otherwise German Control Panel. That is how the CRM
 * modules stayed English for a whole release — nothing failed, they were simply
 * never translated.
 *
 * These tests compare the two directories key by key, in both directions, so a
 * new English string that nobody translates fails here instead of surfacing in
 * somebody's CP, and a German key whose English original was renamed is caught
 * as the dead weight it is.
 */
function leadhubLangDir(string $locale): string
{
    return __DIR__.'/../../resources/lang/'.$locale;
}

/** All keys of a lang array in dot notation, sorted. */
function leadhubFlattenKeys(array $lines, string $prefix = ''): array
{
    $keys = [];

    foreach ($lines as $key => $value) {
        $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $keys = array_merge($keys, leadhubFlattenKeys($value, $full));

            continue;
        }

        $keys[] = $full;
    }

    sort($keys);

    return $keys;
}

/** @return array<string, string> file basename => absolute path */
function leadhubLangFiles(string $locale): array
{
    $files = [];

    foreach (glob(leadhubLangDir($locale).'/*.php') as $path) {
        $files[basename($path)] = $path;
    }

    ksort($files);

    return $files;
}

it('ships a German counterpart for every English lang file', function (): void {
    $missing = array_diff(
        array_keys(leadhubLangFiles('en')),
        array_keys(leadhubLangFiles('de'))
    );

    expect($missing)->toBe([], 'Untranslated lang files: '.implode(', ', $missing));
});

it('has no German lang file without an English original', function (): void {
    $orphans = array_diff(
        array_keys(leadhubLangFiles('de')),
        array_keys(leadhubLangFiles('en'))
    );

    expect($orphans)->toBe([], 'German lang files with no English original: '.implode(', ', $orphans));
});

it('translates every English key into German', function (string $file): void {
    $de = leadhubLangDir('de').'/'.$file;

    expect(file_exists($de))->toBeTrue("resources/lang/de/{$file} does not exist.");

    $missing = array_diff(
        leadhubFlattenKeys(require leadhubLangDir('en').'/'.$file),
        leadhubFlattenKeys(require $de)
    );

    expect(array_values($missing))->toBe([], "Missing German keys in {$file}: ".implode(', ', $missing));
})->with(array_keys(leadhubLangFiles('en')));

it('carries no German key that English does not have', function (string $file): void {
    $en = leadhubLangDir('en').'/'.$file;

    expect(file_exists($en))->toBeTrue("resources/lang/en/{$file} does not exist.");

    $orphans = array_diff(
        leadhubFlattenKeys(require leadhubLangDir('de').'/'.$file),
        leadhubFlattenKeys(require $en)
    );

    expect(array_values($orphans))->toBe([], "Orphaned German keys in {$file}: ".implode(', ', $orphans));
})->with(array_keys(leadhubLangFiles('de')));

it('actually answers in German for the CRM modules', function (): void {
    app()->setLocale('de');

    expect(__('leadhub::companies.title'))->toBe('Firmen')
        ->and(__('leadhub::tasks.title'))->toBe('Aufgaben')
        ->and(__('leadhub::tasks.filters.overdue'))->toBe('Überfällig')
        ->and(__('leadhub::pipelines.title'))->toBe('Pipelines')
        ->and(__('leadhub::pipelines.stage_last'))->toBe('Eine Pipeline braucht mindestens eine Stufe.')
        ->and(__('leadhub::nav.tasks'))->toBe('Aufgaben')
        ->and(__('leadhub::timeline.opportunity_won'))->toBe('Opportunity ":title" gewonnen');
});

/*
|--------------------------------------------------------------------------
| The JSON layer (gap 4)
|--------------------------------------------------------------------------
|
| Everything above this line guards the PHP files, which serve
| __('leadhub::tasks.title') — rendered on the server. The Vue components do
| not use that layer at all. They call __('Tasks'), which is Statamic's
| *string* translation mechanism and reads `{locale}.json` from every
| registered package path. The addon shipped none, in any locale, for eight
| releases: a German install got German navigation, a German timeline and
| English page headings.
|
| The same discipline as above, plus one rule the PHP layer does not need.
| JSON strings from all packages merge into a single global dictionary, so a
| key here overrides that string across the entire Control Panel — not only
| inside this addon. Translating "Save" would rename Statamic's own save
| button everywhere. So the addon's JSON must contain no key that Statamic
| already ships, and the last test below is what enforces that.
|
*/

/** Every __('…') key the Vue/JS sources use, excluding the leadhub:: namespace. */
function leadhubJsKeys(): array
{
    $keys = [];
    $callSites = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../../resources/js')
    );

    foreach ($iterator as $file) {
        if (! in_array($file->getExtension(), ['vue', 'js'], true)) {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        $callSites += substr_count($source, '__(');

        preg_match_all("/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $matches);

        foreach ($matches[1] as $key) {
            $key = str_replace(["\\'", '\\\\'], ["'", '\\'], $key);

            if (! str_starts_with($key, 'leadhub::')) {
                $keys[$key] = true;
            }
        }
    }

    // A __() call whose argument is not a plain single-quoted literal cannot
    // be harvested, and would therefore be a string this test silently does
    // not cover. Fail loudly instead of quietly under-checking.
    $literals = 0;
    foreach ($iterator as $file) {
        if (! in_array($file->getExtension(), ['vue', 'js'], true)) {
            continue;
        }
        $literals += preg_match_all(
            "/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/",
            file_get_contents($file->getPathname())
        );
    }

    expect($literals)->toBe(
        $callSites,
        'Some __() call in resources/js does not pass a single-quoted literal, '
        ."so it cannot be translated or checked. Call sites: {$callSites}, harvestable: {$literals}."
    );

    $keys = array_keys($keys);
    sort($keys);

    return $keys;
}

function leadhubJsonStrings(string $locale): array
{
    $path = __DIR__."/../../resources/lang/{$locale}.json";

    expect(file_exists($path))->toBeTrue("resources/lang/{$locale}.json does not exist.");

    $decoded = json_decode(file_get_contents($path), true);

    expect($decoded)->toBeArray("resources/lang/{$locale}.json is not valid JSON.");

    return $decoded;
}

/** Statamic's own string translations, which the addon must not shadow. */
function statamicCoreStrings(): array
{
    $path = __DIR__.'/../../vendor/statamic/cms/lang/de.json';

    return file_exists($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
}

it('translates every JSON string key into German', function (): void {
    $missing = array_diff(
        array_keys(leadhubJsonStrings('en')),
        array_keys(leadhubJsonStrings('de'))
    );

    expect(array_values($missing))->toBe([], 'Missing German strings: '.implode(' | ', $missing));
});

it('carries no German JSON string that English does not have', function (): void {
    $orphans = array_diff(
        array_keys(leadhubJsonStrings('de')),
        array_keys(leadhubJsonStrings('en'))
    );

    expect(array_values($orphans))->toBe([], 'Orphaned German strings: '.implode(' | ', $orphans));
});

it('leaves no string in the Vue sources untranslatable', function (): void {
    $shipped = array_keys(leadhubJsonStrings('de'));
    $core = array_keys(statamicCoreStrings());

    // A key is covered either by this addon or by Statamic itself. Anything
    // else renders as its raw English source on a German install.
    $uncovered = array_diff(leadhubJsKeys(), $shipped, $core);

    expect(array_values($uncovered))->toBe(
        [],
        'Vue strings with no German anywhere: '.implode(' | ', $uncovered)
    );
});

it('ships no JSON string the Vue sources no longer use', function (): void {
    $dead = array_diff(array_keys(leadhubJsonStrings('en')), leadhubJsKeys());

    expect(array_values($dead))->toBe([], 'Translated strings nothing renders: '.implode(' | ', $dead));
});

it('shadows no Statamic string, because these keys are global to the Control Panel', function (): void {
    $collisions = array_intersect(array_keys(leadhubJsonStrings('de')), array_keys(statamicCoreStrings()));

    expect(array_values($collisions))->toBe(
        [],
        'These keys would override Statamic CP-wide, not just in LeadHub: '.implode(' | ', $collisions)
        .'. Use a key of the addon\'s own instead, e.g. "Archive contact" rather than "Archive".'
    );
});

it('keeps every English JSON key as its own source string', function (): void {
    foreach (leadhubJsonStrings('en') as $key => $value) {
        expect($value)->toBe($key, "en.json must map \"{$key}\" to itself; the keys are the source strings.");
    }
});

it('actually answers in German for the page headings the Vue layer renders', function (): void {
    app()->setLocale('de');

    // The exact strings, not "differs from English". "Pipelines" is
    // "Pipelines" in German and an inequality assertion would call that a
    // failure — while quietly passing anything else that merely differs.
    expect(__('Tasks'))->toBe('Aufgaben')
        ->and(__('Companies'))->toBe('Firmen')
        ->and(__('Contacts'))->toBe('Kontakte')
        ->and(__('Segments'))->toBe('Segmente')
        ->and(__('Pipelines'))->toBe('Pipelines')
        ->and(__('Overdue'))->toBe('Überfällig');

    // The addon must not have taken Statamic's own strings hostage.
    expect(__('Save'))->toBe('Speichern')
        ->and(__('Archive'))->toBe('Archiv')
        ->and(__('Archive contact'))->toBe('Kontakt archivieren');
});
