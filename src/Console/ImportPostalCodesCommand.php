<?php

namespace Goldnead\Leadhub\Console;

use Goldnead\Leadhub\Models\PostalCode;
use Goldnead\Leadhub\Services\PostalCodeRadius;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/**
 * Fills the postal-code table from GeoNames.
 *
 * GeoNames publishes one zip per country at
 * `download.geonames.org/export/zip/<CC>.zip`, all in the same tab-separated
 * layout, under CC BY 4.0. That uniformity is the reason for choosing it over
 * the German-only dataset the old `api.anders-band.de` used: Vienna is a real
 * concert, and a German-only table answers `A-1070` with nothing.
 *
 * A postal code appears more than once in the file — large organisations get
 * their own code, and a district can span several points. The import keeps one
 * row per (country, code) at the mean of its points, which is what a radius of
 * thirty kilometres wants anyway.
 */
class ImportPostalCodesCommand extends Command
{
    protected $signature = 'leadhub:postal-codes
        {countries?* : ISO country codes, e.g. DE AT CH. Defaults to the configured list.}
        {--file= : Import a local GeoNames .txt or .zip instead of downloading}
        {--fresh : Delete the countries being imported before writing}';

    protected $description = 'Import postal-code coordinates from GeoNames';

    protected const SOURCE = 'https://download.geonames.org/export/zip/%s.zip';

    /** Column positions in the GeoNames postal-code layout. */
    protected const COL_COUNTRY = 0;

    protected const COL_CODE = 1;

    protected const COL_PLACE = 2;

    protected const COL_REGION = 3;

    protected const COL_LAT = 9;

    protected const COL_LNG = 10;

    public function handle(PostalCodeRadius $radius): int
    {
        $countries = array_map(
            'strtoupper',
            $this->argument('countries') ?: (array) config('leadhub.postal_codes.countries', ['DE', 'AT', 'CH'])
        );

        foreach ($countries as $country) {
            if (! preg_match('/^[A-Z]{2}$/', $country)) {
                $this->components->error("Kein ISO-Laendercode: {$country}");

                return self::FAILURE;
            }
        }

        foreach ($countries as $country) {
            $lines = $this->lines($country);

            if ($lines === null) {
                return self::FAILURE;
            }

            $this->store($country, $lines);
        }

        // The radius answers are derived from what was just replaced.
        $radius->forget();
        $this->call('cache:clear');

        $this->components->twoColumnDetail('Postleitzahlen gesamt', (string) PostalCode::query()->count());

        return self::SUCCESS;
    }

    /**
     * The raw lines for one country, from a local file or from GeoNames.
     *
     * @return list<string>|null
     */
    protected function lines(string $country): ?array
    {
        $path = $this->option('file');

        if (! $path) {
            $path = tempnam(sys_get_temp_dir(), 'leadhub-plz').'.zip';
            $url = sprintf(self::SOURCE, $country);

            $this->components->task("{$country}: laden", function () use ($url, $path): bool {
                $body = @file_get_contents($url);

                return $body !== false && $body !== '' && file_put_contents($path, $body) !== false;
            });

            if (! is_file($path) || filesize($path) === 0) {
                $this->components->error("{$country}: Download fehlgeschlagen ({$url})");

                return null;
            }
        }

        if (! is_file($path)) {
            $this->components->error("Datei nicht gefunden: {$path}");

            return null;
        }

        $text = str_ends_with(strtolower($path), '.zip')
            ? $this->unzip($path, $country)
            : file_get_contents($path);

        if ($text === null || $text === false || trim($text) === '') {
            $this->components->error("{$country}: keine verwertbaren Zeilen");

            return null;
        }

        return array_values(array_filter(explode("\n", $text), fn ($line) => trim($line) !== ''));
    }

    protected function unzip(string $path, string $country): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return null;
        }

        // The archive holds <CC>.txt plus a readme. Take the data file.
        $text = $zip->getFromName($country.'.txt');

        if ($text === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);

                if ($name && str_ends_with(strtolower($name), '.txt') && ! str_contains(strtolower($name), 'readme')) {
                    $text = $zip->getFromName($name);
                    break;
                }
            }
        }

        $zip->close();

        return $text === false ? null : $text;
    }

    /** @param  list<string>  $lines */
    protected function store(string $country, array $lines): void
    {
        /** @var array<string, array{place: ?string, region: ?string, lat: list<float>, lng: list<float>}> */
        $byCode = [];

        /** @var array<string, int> Rows the file carried for other countries. */
        $foreign = [];

        foreach ($lines as $line) {
            $cols = explode("\t", $line);

            if (count($cols) <= self::COL_LNG) {
                continue;
            }

            // The file states its own country in the first column. Trusting the
            // argument instead would file an Austrian row under DE the moment
            // someone points --file at a mixed export, and a wrong country is
            // invisible: the row looks fine and simply never matches.
            $rowCountry = strtoupper(trim($cols[self::COL_COUNTRY])) ?: $country;

            if ($rowCountry !== $country) {
                $foreign[$rowCountry] = ($foreign[$rowCountry] ?? 0) + 1;

                continue;
            }

            $code = PostalCode::normalise($cols[self::COL_CODE]);
            $lat = $cols[self::COL_LAT];
            $lng = $cols[self::COL_LNG];

            // GeoNames leaves the coordinates empty for a handful of codes.
            // Those are dropped rather than stored at 0,0 — a point in the
            // Atlantic looks like a real answer to a radius query.
            if ($code === '' || ! is_numeric($lat) || ! is_numeric($lng)) {
                continue;
            }

            $byCode[$code] ??= [
                'place' => $cols[self::COL_PLACE] ?: null,
                'region' => $cols[self::COL_REGION] ?: null,
                'lat' => [],
                'lng' => [],
            ];

            $byCode[$code]['lat'][] = (float) $lat;
            $byCode[$code]['lng'][] = (float) $lng;
        }

        if ($byCode === []) {
            $this->components->warn("{$country}: keine Zeilen erkannt");

            return;
        }

        if ($this->option('fresh')) {
            PostalCode::query()->where('country', $country)->delete();
        }

        $now = now();
        $rows = [];

        foreach ($byCode as $code => $data) {
            $rows[] = [
                'country' => $country,
                'postal_code' => $code,
                'place' => $data['place'],
                'region' => $data['region'],
                'latitude' => round(array_sum($data['lat']) / count($data['lat']), 7),
                'longitude' => round(array_sum($data['lng']) / count($data['lng']), 7),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Chunked because SQLite caps the number of bound variables per
        // statement, and eight columns times ten thousand rows walks past it.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('leadhub_postal_codes')->upsert(
                $chunk,
                ['country', 'postal_code'],
                ['place', 'region', 'latitude', 'longitude', 'updated_at']
            );
        }

        $this->components->twoColumnDetail($country, count($rows).' Postleitzahlen');

        // Nicht verschweigen: uebersprungene Zeilen wuerden sonst als
        // fehlende Daten durchgehen, und ein zu kleines Segment sieht dann nach
        // einem zu engen Radius aus.
        foreach ($foreign as $land => $anzahl) {
            $this->components->warn("{$anzahl} Zeile(n) fuer {$land} uebersprungen — mit `leadhub:postal-codes {$land}` einlesen.");
        }
    }
}
