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
