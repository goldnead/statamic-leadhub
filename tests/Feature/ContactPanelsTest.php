<?php

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\ContactPanels;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\User;

/**
 * The registry that lets a sibling addon put what it knows on the contact page.
 *
 * The direction of the dependency is what this is for: marketing requires
 * LeadHub, LeadHub requires nobody, and "which mailing lists is this person on"
 * still has to be answerable on the screen somebody opens to find out. So the
 * sibling registers and this addon renders whatever it is handed — which means
 * the guarantees worth testing are about surviving a contributor that
 * misbehaves, not about any particular panel.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('The contact screen props target the eloquent driver.');
    }

    $user = User::make()->email('panels@example.com')->makeSuper();
    $user->save();
    $this->actingAs($user);

    $this->contact = Contact::create(['email' => 'reader@example.com']);

    $this->panels = app(ContactPanels::class);

    $this->props = fn () => json_decode(
        $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('leadhub.contacts.show', $this->contact->uuid))
            ->assertStatus(200)
            ->getContent(),
        true,
    )['props'] ?? [];
});

it('hands a registered panel to the contact screen', function (): void {
    LeadHub::registerContactPanel('test.lists', fn ($contact) => [
        'heading' => 'Mailing lists',
        'description' => 'What this person receives.',
        'rows' => [[
            'label' => 'The newsletter',
            'url' => '/cp/somewhere',
            'meta' => 'since March',
            'badge' => ['text' => 'Subscribed', 'color' => 'green'],
        ]],
    ]);

    $panels = ($this->props)()['contactPanels'];

    expect($panels)->toHaveCount(1)
        ->and($panels[0]['key'])->toBe('test.lists')
        ->and($panels[0]['heading'])->toBe('Mailing lists')
        ->and($panels[0]['rows'][0]['label'])->toBe('The newsletter')
        ->and($panels[0]['rows'][0]['badge']['color'])->toBe('green');
});

it('gives the resolver the contact it is about', function (): void {
    LeadHub::registerContactPanel('test.echo', fn ($contact) => [
        'heading' => 'Echo',
        'rows' => [['label' => $contact->email]],
    ]);

    expect(($this->props)()['contactPanels'][0]['rows'][0]['label'])->toBe('reader@example.com');
});

it('registers a key once, however often it is registered', function (): void {
    // Marketing boots its bridges from a doubled `booted()` callback, so the
    // same panel is registered twice on every request there. Twice on screen
    // would be the visible half of that; the invisible half is that the second
    // registration is the one that counts.
    LeadHub::registerContactPanel('test.dup', fn () => ['heading' => 'First', 'rows' => [['label' => 'a']]]);
    LeadHub::registerContactPanel('test.dup', fn () => ['heading' => 'Second', 'rows' => [['label' => 'a']]]);

    $panels = ($this->props)()['contactPanels'];

    expect($panels)->toHaveCount(1)
        ->and($panels[0]['heading'])->toBe('Second');
});

it('leaves out a panel that has nothing to say', function (): void {
    // Null is a legitimate answer — a contact with no address cannot be on a
    // list at all — and an empty box saying so is worse than no box.
    LeadHub::registerContactPanel('test.silent', fn () => null);
    LeadHub::registerContactPanel('test.norows', fn () => ['heading' => 'Nothing', 'rows' => []]);

    expect(($this->props)()['contactPanels'])->toBe([]);
});

it('keeps a panel with no rows when it brought an empty state', function (): void {
    // "On no list" is a real answer and worth a box; "nothing to say" is not.
    LeadHub::registerContactPanel('test.empty', fn () => [
        'heading' => 'Mailing lists',
        'rows' => [],
        'empty' => 'Not on any mailing list.',
    ]);

    $panels = ($this->props)()['contactPanels'];

    expect($panels)->toHaveCount(1)
        ->and($panels[0]['empty'])->toBe('Not on any mailing list.');
});

it('renders the page when a contributor throws, and says so in the log', function (): void {
    // The load-bearing one. This runs while the contact screen renders, and a
    // sibling addon mid-upgrade must not be able to 500 the page somebody
    // opened to read a phone number.
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'test.broken'));

    LeadHub::registerContactPanel('test.broken', function () {
        throw new RuntimeException('the sibling is mid-upgrade');
    });

    LeadHub::registerContactPanel('test.fine', fn () => [
        'heading' => 'Still here',
        'rows' => [['label' => 'a']],
    ]);

    $panels = ($this->props)()['contactPanels'];

    expect($panels)->toHaveCount(1)
        ->and($panels[0]['key'])->toBe('test.fine');
});

it('drops a row without a label rather than rendering a blank line', function (): void {
    LeadHub::registerContactPanel('test.rows', fn () => [
        'heading' => 'Rows',
        'rows' => [
            ['label' => 'good'],
            ['meta' => 'no label'],
            'not even an array',
        ],
    ]);

    $rows = ($this->props)()['contactPanels'][0]['rows'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['label'])->toBe('good');
});

it('fills in the optional parts of a row so the view never guards', function (): void {
    LeadHub::registerContactPanel('test.sparse', fn () => [
        'heading' => 'Sparse',
        'rows' => [['label' => 'bare']],
    ]);

    $row = ($this->props)()['contactPanels'][0]['rows'][0];

    expect($row)->toHaveKeys(['label', 'url', 'meta', 'badge'])
        ->and($row['url'])->toBeNull()
        ->and($row['badge'])->toBeNull();
});

it('is empty on an install with no sibling addons', function (): void {
    expect($this->panels->keys())->toBe([])
        ->and(($this->props)()['contactPanels'])->toBe([]);
});

// ── The select-shaped action ────────────────────────────────────────────────
//
// A contributor can offer "do the thing from here" — put this person on a
// mailing list — without LeadHub learning what a list is: each option carries
// the URL it posts to and the body it sends. Everything below is the contract
// the registry promises them, so it has to be pinned.

it('keeps a select-shaped action, option payload and all', function (): void {
    LeadHub::registerContactPanel('test.select', fn () => [
        'heading' => 'Lists',
        'empty' => 'On no list.',
        'rows' => [],
        'action' => [
            'text' => 'Add to list',
            'icon' => 'plus',
            'select' => [
                'placeholder' => 'Pick a list…',
                'options' => [[
                    'value' => 'chorbrief',
                    'label' => 'Der Chorbrief',
                    'url' => '/cp/marketing/lists/chorbrief/subscribers',
                    'payload' => ['email' => 'someone@example.com'],
                ]],
            ],
        ],
    ]);

    $action = ($this->props)()['contactPanels'][0]['action'];

    expect($action['text'])->toBe('Add to list')
        ->and($action['icon'])->toBe('plus')
        ->and($action['select']['placeholder'])->toBe('Pick a list…')
        ->and($action['select']['options'])->toHaveCount(1)
        ->and($action['select']['options'][0]['url'])->toBe('/cp/marketing/lists/chorbrief/subscribers')
        ->and($action['select']['options'][0]['payload'])->toBe(['email' => 'someone@example.com']);
});

it('drops a select option that cannot be posted to', function (): void {
    // A half-filled option renders a button that does nothing. Better to leave
    // it out of the list than to draw it.
    LeadHub::registerContactPanel('test.partial', fn () => [
        'heading' => 'Lists',
        'empty' => 'On no list.',
        'rows' => [],
        'action' => [
            'text' => 'Add to list',
            'select' => ['options' => [
                ['value' => 'ok', 'label' => 'Fine', 'url' => '/cp/x'],
                ['value' => 'no-url', 'label' => 'Missing its URL'],
                ['label' => 'Missing its value', 'url' => '/cp/y'],
                'not even an array',
            ]],
        ],
    ]);

    $options = ($this->props)()['contactPanels'][0]['action']['select']['options'];

    expect($options)->toHaveCount(1)
        ->and($options[0]['value'])->toBe('ok')
        // An option that named no payload still gets one, so the view never guards.
        ->and($options[0]['payload'])->toBe([]);
});

it('drops the whole action when no option survives', function (): void {
    LeadHub::registerContactPanel('test.no-options', fn () => [
        'heading' => 'Lists',
        'empty' => 'On no list.',
        'rows' => [],
        'action' => ['text' => 'Add to list', 'select' => ['options' => []]],
    ]);

    expect(($this->props)()['contactPanels'][0]['action'])->toBeNull();
});

it('drops a plain action that names no url', function (): void {
    LeadHub::registerContactPanel('test.linkless', fn () => [
        'heading' => 'Lists',
        'empty' => 'On no list.',
        'rows' => [],
        'action' => ['text' => 'Goes nowhere'],
    ]);

    expect(($this->props)()['contactPanels'][0]['action'])->toBeNull();
});
