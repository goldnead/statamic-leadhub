<?php

use Goldnead\Leadhub\Contracts\EmailTemplateSource;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Services\EmailTemplates\EmailTemplateCollectionManager;
use Goldnead\Leadhub\Services\EmailTemplates\EmailTemplateResolver;
use Goldnead\Leadhub\Support\EmailTemplates\EmailTemplateBlueprint;
use Goldnead\Leadhub\Support\EmailTemplates\EmailTemplateData;
use Illuminate\Support\Facades\Artisan;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

// The collections/entries Stache is redirected to a per-process temp dir that
// persists across tests in a run, so clear any leftover entries up front.
beforeEach(function () {
    Collection::findByHandle('email_templates')?->queryEntries()->get()->each->delete();
});

/** An in-memory template source for exercising the import command. */
function fakeTemplateSource(array $templates): EmailTemplateSource
{
    return new class($templates) implements EmailTemplateSource
    {
        public function __construct(private array $templates)
        {
        }

        public function label(): string
        {
            return 'fake';
        }

        public function all(): array
        {
            return array_map(fn (array $t) => EmailTemplateData::fromArray($t), $this->templates);
        }
    };
}

function registerFakeSource(EmailTemplateSource $source): void
{
    app()->instance('leadhub.test.fake_source', $source);
    app()->tag(['leadhub.test.fake_source'], 'leadhub.email_template_sources');
}

// -- Slice 1: collection + blueprint + entry CRUD -------------------------

it('registers the email_templates collection and its blueprint', function () {
    // bootAddon() (fired in TestCase::setUp) already ran ensure(); assert result.
    expect(Collection::findByHandle('email_templates'))->not->toBeNull();

    $blueprint = Blueprint::find(
        EmailTemplateBlueprint::NAMESPACE.'.'.EmailTemplateBlueprint::HANDLE
    );

    expect($blueprint)->not->toBeNull()
        ->and($blueprint->hasField('subject'))->toBeTrue()
        ->and($blueprint->hasField('body'))->toBeTrue()
        ->and($blueprint->field('body')->type())->toBe('code');
});

it('creates and reads a template entry by slug', function () {
    $manager = app(EmailTemplateCollectionManager::class);

    [$entry, $created] = $manager->upsert(new EmailTemplateData(
        slug: 'welcome',
        title: 'Welcome',
        subject: 'Willkommen, {{ contact.first_name }}',
        body: '<h1>Hallo</h1>',
    ));

    expect($created)->toBeTrue()
        ->and($entry->slug())->toBe('welcome');

    $found = $manager->findBySlug('welcome');

    expect($found)->not->toBeNull()
        ->and($found->value('subject'))->toBe('Willkommen, {{ contact.first_name }}')
        ->and($found->value('body'))->toBe('<h1>Hallo</h1>');
});

// -- Slice 2: import command ---------------------------------------------

it('imports file-based templates into entries, preserving the slug', function () {
    registerFakeSource(fakeTemplateSource([
        ['handle' => 'newsletter', 'name' => 'Newsletter', 'html' => '<p>Body</p>'],
    ]));

    $code = Artisan::call('leadhub:email-templates:import');

    expect($code)->toBe(0);

    $entry = app(EmailTemplateCollectionManager::class)->findBySlug('newsletter');

    expect($entry)->not->toBeNull()
        ->and($entry->slug())->toBe('newsletter')
        ->and($entry->value('title'))->toBe('Newsletter')
        ->and($entry->value('body'))->toBe('<p>Body</p>');
});

it('skips existing slugs unless --overwrite is passed', function () {
    $manager = app(EmailTemplateCollectionManager::class);
    $manager->upsert(new EmailTemplateData(slug: 'promo', title: 'Old', body: 'OLD'));

    registerFakeSource(fakeTemplateSource([
        ['slug' => 'promo', 'title' => 'New', 'body' => 'NEW'],
    ]));

    Artisan::call('leadhub:email-templates:import');
    expect($manager->findBySlug('promo')->value('body'))->toBe('OLD');

    Artisan::call('leadhub:email-templates:import', ['--overwrite' => true]);
    expect($manager->findBySlug('promo')->value('body'))->toBe('NEW');
});

it('reports nothing to import when no source yields templates', function () {
    $code = Artisan::call('leadhub:email-templates:import');

    expect($code)->toBe(0);
    expect(Artisan::output())->toContain('Nothing to import');
});

// -- Slice 3: resolver with fallback -------------------------------------

it('resolves the entry template over the file fallback (entry wins)', function () {
    app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(
        slug: 'reminder',
        title: 'Reminder',
        subject: 'From entry',
        body: 'ENTRY BODY',
    ));

    $fallbackCalled = false;
    $resolved = app(EmailTemplateResolver::class)->resolve('reminder', function () use (&$fallbackCalled) {
        $fallbackCalled = true;

        return ['title' => 'File', 'body' => 'FILE BODY'];
    });

    expect($fallbackCalled)->toBeFalse()
        ->and($resolved)->not->toBeNull()
        ->and($resolved->source)->toBe('entry')
        ->and($resolved->body)->toBe('ENTRY BODY');
});

it('falls back to the file template when no entry exists', function () {
    $resolved = app(EmailTemplateResolver::class)->resolve('missing', fn () => [
        'title' => 'File',
        'body' => 'FILE BODY',
    ]);

    expect($resolved)->not->toBeNull()
        ->and($resolved->source)->toBe('fallback')
        ->and($resolved->slug)->toBe('missing')
        ->and($resolved->body)->toBe('FILE BODY');
});

it('returns null when neither an entry nor a fallback yields a template', function () {
    expect(app(EmailTemplateResolver::class)->resolve('nope'))->toBeNull();
    expect(app(EmailTemplateResolver::class)->resolve('nope', fn () => null))->toBeNull();
});

it('exposes resolveEmailTemplate through the LeadHub facade', function () {
    app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(
        slug: 'facade',
        title: 'Facade',
        body: 'VIA FACADE',
    ));

    $result = LeadHub::resolveEmailTemplate('facade');

    expect($result)->toBeArray()
        ->and($result['slug'])->toBe('facade')
        ->and($result['body'])->toBe('VIA FACADE')
        ->and($result['source'])->toBe('entry');
});
