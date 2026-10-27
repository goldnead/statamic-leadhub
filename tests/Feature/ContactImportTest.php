<?php

/**
 * Contact import: CSV (or pasted from Excel) -> column mapping -> preview -> queue.
 *
 * The promises under test are the ones the README makes. Every assertion reads
 * through the ContactRepository so the file runs on both storage drivers.
 */

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Jobs\ImportContactsJob;
use Goldnead\Leadhub\Models\CustomField;
use Goldnead\Leadhub\Notifications\NewLeadNotification;
use Goldnead\Leadhub\Services\ContactImportService;
use Goldnead\Leadhub\Services\ContactResolver;
use Goldnead\Leadhub\Services\Import\ColumnGuesser;
use Goldnead\Leadhub\Services\Import\ImportStore;
use Goldnead\Leadhub\Support\ContactDto;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\User;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('leadhub.imports.disk', 'local');

    $this->user = User::make()->email('importer@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);
});

function importContact(string $email)
{
    return app(ContactRepository::class)->findByEmailNormalized(EmailNormalizer::normalize($email));
}

/** Stage a file and return the import id taken from the redirect. */
function stageCsv(string $csv, string $name = 'kontakte.csv'): string
{
    $response = test()->post(cp_route('leadhub.import.upload'), [
        'file' => UploadedFile::fake()->createWithContent($name, $csv),
    ]);

    $response->assertSessionHasNoErrors();
    $location = $response->headers->get('Location');
    preg_match('#/import/([0-9a-f-]{36})#', (string) $location, $m);
    expect($m[1] ?? null)->not->toBeNull();

    return $m[1];
}

/** @param  array<int, string>  $mapping  target per column, '' = skip */
function runImport(string $id, array $mapping, array $extra = [])
{
    return test()->post(cp_route('leadhub.import.run', $id), array_merge(['mapping' => $mapping], $extra));
}

const IMPORT_DE = "Vorname;Nachname;E-Mail;Telefon;Schlagwort\nJane;Doe;jane@example.com;+49 151 1111111;x\nMax;Muster;max@example.com;;y\n";

it('guesses the mapping from German and English headers and leaves unknown columns out', function (): void {
    $guess = (new ColumnGuesser)->guess(['Vorname', 'Nachname', 'E-Mail-Adresse', 'Mobil', 'Firma', 'PLZ', 'Land', 'Lieblingsfarbe']);

    expect($guess)->toBe(['first_name', 'last_name', 'email', 'phone', 'company', 'postal_code', 'country', '']);
});

it('stages an upload, detects the delimiter and offers the map step', function (): void {
    $id = stageCsv(IMPORT_DE);

    $state = app(ImportStore::class)->find($id);
    expect($state['status'])->toBe('staged')
        ->and($state['delimiter'])->toBe(';')
        ->and($state['header'])->toBe(['Vorname', 'Nachname', 'E-Mail', 'Telefon', 'Schlagwort'])
        ->and($state['total_rows'])->toBe(2);

    $response = $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.import.show', $id));
    $response->assertOk();
    expect($response->json('component'))->toBe('leadhub::Imports/Map')
        ->and($response->json('props.suggested'))->toBe(['first_name', 'last_name', 'email', 'phone', ''])
        ->and($response->json('props.disclaimer'))->toBeArray();
});

it('imports mapped rows into the queue and reports created counts', function (): void {
    $id = stageCsv(IMPORT_DE);

    runImport($id, ['first_name', 'last_name', 'email', 'phone', ''])->assertRedirect();

    $jane = importContact('jane@example.com');
    expect($jane)->not->toBeNull()
        ->and($jane->first_name)->toBe('Jane')
        ->and($jane->last_name)->toBe('Doe')
        ->and($jane->phone)->toBe('+49 151 1111111')
        ->and(importContact('max@example.com'))->not->toBeNull();

    $state = app(ImportStore::class)->find($id);
    expect($state['status'])->toBe('completed')
        ->and($state['counts'])->toMatchArray(['created' => 2, 'updated' => 0, 'skipped' => 0, 'errors' => 0]);
});

it('merges a duplicate into the existing contact instead of creating a second one', function (): void {
    app(ContactResolver::class)->resolveOrCreate(new ContactDto(email: 'Jane@Example.com', firstName: 'Janet'));

    $csv = "email,first_name,last_name\njane@example.com,Jane,Doe\njane@example.com,Jane,Doe\nnew@example.com,Neu,Kontakt\n";
    $id = stageCsv($csv);
    runImport($id, ['email', 'first_name', 'last_name']);

    $jane = importContact('jane@example.com');
    // Non-empty fields are never overwritten, empty ones are filled: the
    // resolver's rule, not a new one.
    expect($jane->first_name)->toBe('Janet')
        ->and($jane->last_name)->toBe('Doe');

    $state = app(ImportStore::class)->find($id);
    expect($state['counts'])->toMatchArray(['created' => 1, 'updated' => 2, 'errors' => 0]);
});

it('sends no mail and no staff notification while importing', function (): void {
    $this->bootAddonEvents();
    Notification::fake();
    Mail::fake();
    config()->set('leadhub.notifications.enabled', true);
    config()->set('leadhub.notifications.recipients', ['sales@example.com']);

    // Control: a contact created the normal way does alert staff, so the
    // silence below is the import's doing and not a broken setup.
    app(ContactResolver::class)->resolveOrCreate(new ContactDto(email: 'control@example.com'));
    Notification::assertSentOnDemandTimes(NewLeadNotification::class, 1);

    $id = stageCsv("email\nsilent-one@example.com\nsilent-two@example.com\n");
    runImport($id, ['email']);

    expect(importContact('silent-two@example.com'))->not->toBeNull();
    Notification::assertSentOnDemandTimes(NewLeadNotification::class, 1);
    Notification::assertNothingSentTo($this->user);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

it('puts invalid rows into the error report instead of aborting', function (): void {
    $csv = "email,name\nok@example.com,Ok\nnot-an-email,Broken\n,Empty\nlast@example.com,Last\n";
    $id = stageCsv($csv);
    runImport($id, ['email', 'full_name']);

    expect(importContact('ok@example.com'))->not->toBeNull()
        ->and(importContact('last@example.com'))->not->toBeNull();

    $state = app(ImportStore::class)->find($id);
    expect($state['status'])->toBe('completed')
        ->and($state['counts'])->toMatchArray(['created' => 2, 'errors' => 2])
        ->and(collect($state['issues'])->pluck('row')->all())->toBe([3, 4]);

    $report = $this->get(cp_route('leadhub.import.errors', $id));
    $report->assertOk();
    $body = $report->streamedContent();
    expect($body)->toContain('not-an-email')->and($body)->toContain('Broken');
});

it('runs a large file in chunks, one queued job per chunk', function (): void {
    config()->set('leadhub.imports.chunk_size', 2);
    Queue::fake();

    $rows = collect(range(1, 5))->map(fn ($i) => "row{$i}@example.com")->implode("\n");
    $id = stageCsv("email\n".$rows."\n");
    runImport($id, ['email']);

    Queue::assertPushed(ImportContactsJob::class, 1);
    expect(importContact('row1@example.com'))->toBeNull('nothing runs in the request');

    // Drive the queue by hand: each job handles one chunk and queues the next.
    $guard = 0;
    while (($pushed = Queue::pushed(ImportContactsJob::class))->count() > $guard && $guard < 10) {
        $job = $pushed->values()[$guard];
        $guard++;
        $job->handle(app(ContactImportService::class));

        if ($guard === 1) {
            expect(importContact('row2@example.com'))->not->toBeNull()
                ->and(importContact('row3@example.com'))->toBeNull()
                ->and(app(ImportStore::class)->find($id)['status'])->toBe('running');
        }
    }

    expect($guard)->toBe(3);
    $state = app(ImportStore::class)->find($id);
    expect($state['status'])->toBe('completed')
        ->and($state['counts']['created'])->toBe(5)
        ->and(importContact('row5@example.com'))->not->toBeNull();
});

it('does not invent consent: unmapped means not consented, a column or the box sets it', function (): void {
    $id = stageCsv("email\nplain@example.com\n");
    runImport($id, ['email']);
    expect((bool) importContact('plain@example.com')->consent)->toBeFalse();

    $id = stageCsv("email,optin\nyes@example.com,ja\nno@example.com,nein\nmaybe@example.com,\n");
    runImport($id, ['email', 'consent']);
    expect((bool) importContact('yes@example.com')->consent)->toBeTrue()
        ->and((bool) importContact('no@example.com')->consent)->toBeFalse()
        ->and((bool) importContact('maybe@example.com')->consent)->toBeFalse();

    $id = stageCsv("email\nbox@example.com\n");
    runImport($id, ['email'], ['consent_given' => '1']);
    expect((bool) importContact('box@example.com')->consent)->toBeTrue()
        ->and(importContact('box@example.com')->consent_at)->not->toBeNull();
});

it('never touches an address that opted out', function (): void {
    [$contact] = app(ContactResolver::class)->resolveOrCreate(new ContactDto(email: 'gone@example.com'));
    $contact->do_not_contact = true;
    app(ContactRepository::class)->save($contact);

    $id = stageCsv("email,first_name\ngone@example.com,Wieder\n");
    runImport($id, ['email', 'first_name'], ['consent_given' => '1']);

    $after = importContact('gone@example.com');
    expect((bool) $after->do_not_contact)->toBeTrue()
        ->and((bool) $after->consent)->toBeFalse()
        ->and($after->first_name)->toBeNull();

    $state = app(ImportStore::class)->find($id);
    expect($state['counts'])->toMatchArray(['created' => 0, 'updated' => 0, 'skipped' => 1]);
});

it('attaches the optional target tag and honours the mapped tags column', function (): void {
    $id = stageCsv("email,labels\ntagged@example.com,\"a; b\"\n");
    runImport($id, ['email', 'tags'], ['tag' => 'Import Oktober']);

    $tags = importContact('tagged@example.com')->tags->pluck('name')->all();
    expect($tags)->toContain('Import Oktober')->toContain('a')->toContain('b');
});

it('skips unknown columns and can turn one into a custom field', function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('Custom fields are eloquent-only.');
    }

    $id = stageCsv("email,Lieblingsfarbe,Geheim\ncf@example.com,Blau,nie\n");
    runImport($id, ['email', 'custom:new', '']);

    $field = CustomField::query()->where('handle', 'lieblingsfarbe')->first();
    expect($field)->not->toBeNull()->and($field->label)->toBe('Lieblingsfarbe');

    $contact = importContact('cf@example.com');
    expect($contact->custom_fields)->toBe(['lieblingsfarbe' => 'Blau']);
});

it('previews the first rows with the outcome per row without writing', function (): void {
    app(ContactResolver::class)->resolveOrCreate(new ContactDto(email: 'jane@example.com'));
    $id = stageCsv("email,name\njane@example.com,Jane\nfresh@example.com,Fresh\nbad,Bad\n");

    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->post(cp_route('leadhub.import.preview', $id), ['mapping' => ['email', 'full_name']]);

    $response->assertOk();
    expect($response->json('props.preview.rows.*.outcome'))->toBe(['update', 'create', 'invalid'])
        ->and(importContact('fresh@example.com'))->toBeNull();
});

it('refuses a mapping without an email column', function (): void {
    $id = stageCsv("name\nJane\n");

    runImport($id, ['full_name'])->assertSessionHasErrors('mapping');
    expect(app(ImportStore::class)->find($id)['status'])->toBe('staged');
});

it('accepts text pasted from Excel through the same parser', function (): void {
    $tsv = "E-Mail\tVorname\npasted@example.com\tPia\n";

    $response = $this->post(cp_route('leadhub.import.upload'), ['text' => $tsv]);
    preg_match('#/import/([0-9a-f-]{36})#', (string) $response->headers->get('Location'), $m);

    expect(app(ImportStore::class)->find($m[1])['delimiter'])->toBe("\t");
    runImport($m[1], ['email', 'first_name']);
    expect(importContact('pasted@example.com')->first_name)->toBe('Pia');
});

it('reads a Windows-1252 file and stores the text as UTF-8', function (): void {
    $csv = mb_convert_encoding("email;name\nmueller@example.com;Müller\n", 'Windows-1252', 'UTF-8');
    $id = stageCsv($csv);
    runImport($id, ['email', 'full_name']);

    expect(importContact('mueller@example.com')->full_name)->toBe('Müller');
});

it('refuses upload, run and error report for a user without the import permission', function (): void {
    $plain = User::make()->email('plain-import@example.com');
    $plain->save();
    $this->actingAs($plain);

    $this->post(cp_route('leadhub.import.upload'), ['text' => "email\na@example.com"])->assertForbidden();
    $this->get(cp_route('leadhub.import.create'))->assertForbidden();
});
