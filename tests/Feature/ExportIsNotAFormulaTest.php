<?php

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Services\ExportService;

/**
 * A contact export must not hand a colleague an executable file.
 *
 * Excel and LibreOffice run a cell that begins with `=`, `+`, `-` or `@` the
 * moment the file is opened. Every name, company and tag in this export can
 * come from a stranger filling in a public form — so an attacker chooses the
 * content of a cell that a colleague later opens on their own machine, with
 * whatever rights that machine has.
 *
 * Two of this family's three CSV exports carried the guard and explained it at
 * length. This one — the only one fed by strangers — did not.
 */
it('neutralises a formula a stranger typed into a public form', function () {
    Contact::factory()->create([
        'first_name' => '=cmd|" /C calc"!A0',
        'last_name' => 'Tabelle',
        'full_name' => '=cmd|" /C calc"!A0 Tabelle',
        'email' => 'angriff@example.com',
    ]);

    $pfad = app(ExportService::class)->generateCsv([]);
    $inhalt = file_get_contents($pfad);

    expect($inhalt)->toContain('\'=cmd')
        ->and($inhalt)->not->toContain('"=cmd');

    @unlink($pfad);
});

it('neutralises every leading character a spreadsheet treats as code', function (string $anfang) {
    Contact::factory()->create([
        'first_name' => $anfang.'HYPERLINK("http://boese.example")',
        'last_name' => 'Probe',
        'full_name' => $anfang.'HYPERLINK("http://boese.example") Probe',
        'email' => 'probe'.bin2hex(random_bytes(4)).'@example.com',
    ]);

    $pfad = app(ExportService::class)->generateCsv([]);

    expect(file_get_contents($pfad))->toContain("'".$anfang.'HYPERLINK');

    @unlink($pfad);
})->with(['=', '+', '-', '@', "\t", "\r"]);

it('leaves an ordinary name exactly as it is', function () {
    // The guard must not become a second bug: an apostrophe in front of every
    // value would corrupt the export it was meant to protect.
    Contact::factory()->create([
        'first_name' => 'Bärbel',
        'last_name' => 'Öztürk-Weiß',
        'full_name' => 'Bärbel Öztürk-Weiß',
        'email' => 'baerbel@example.com',
    ]);

    $inhalt = file_get_contents($pfad = app(ExportService::class)->generateCsv([]));

    expect($inhalt)->toContain('Bärbel Öztürk-Weiß')
        ->and($inhalt)->not->toContain("'Bärbel");

    @unlink($pfad);
});
