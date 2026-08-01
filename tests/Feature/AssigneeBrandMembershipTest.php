<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Facades\BrandMembers;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Models\BrandUser;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\UserDirectory;
use Statamic\Facades\User;

/**
 * Who is offered as an assignee, and as a contact owner, per brand.
 *
 * Gap 5, closed. "Assignees are the CP users of the respective brand" was the
 * decision behind task assignment in v1.7.0 and could not be built:
 * brand-context isolated Eloquent models, and a Statamic user is not one. It
 * ships a `brand_user` pivot since 1.5.0, so the list can finally be narrowed —
 * and the narrowing has to happen in two places, because contact ownership has
 * had the same hole since 1.0.
 *
 * The case that matters most here is not the isolation but the **transition
 * rule**: a user with no membership anywhere counts as a member of every brand.
 * Every install upgrading into this feature starts with an empty pivot table,
 * so a filter that ignored the rule would empty every dropdown on the day of
 * the upgrade and look exactly like a permissions failure. It is asserted first
 * and from both ends.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('CRM-core CP screens target the eloquent driver.');
    }

    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    config()->set('leadhub.features.tasks', true);
    config()->set('leadhub.features.pipelines', true);

    $this->brandA = Brand::create(['handle' => 'members-a', 'name' => 'Members A']);
    $this->brandB = Brand::create(['handle' => 'members-b', 'name' => 'Members B']);

    $this->admin = User::make()->email('members-admin@example.com')->makeSuper();
    $this->admin->save();
    $this->actingAs($this->admin);
});

/** The assignee list as UserDirectory derives it for one brand. */
function assigneesIn($brand): array
{
    BrandContext::setCurrent($brand);

    return collect(app(UserDirectory::class)->assignable())->pluck('value')->all();
}

// ------------------------------------------------------- the transition rule

it('offers a user with no membership at all in every brand', function (): void {
    // Nobody has been assigned anywhere: this is what an install looks like the
    // minute it upgrades into brand membership.
    $unassigned = User::make()->email('members-unassigned@example.com')->makeSuper();
    $unassigned->save();

    expect(BrandMembers::isUnassigned($unassigned))->toBeTrue();

    expect(assigneesIn($this->brandA))->toContain((string) $unassigned->id())
        ->and(assigneesIn($this->brandB))->toContain((string) $unassigned->id());
});

it('keeps every list non-empty while the membership table is empty', function (): void {
    // The failure mode this rule exists to prevent, stated as a whole-list
    // assertion rather than a per-user one: an upgrade must not silently empty
    // the dropdowns.
    $one = User::make()->email('members-empty-one@example.com')->makeSuper();
    $one->save();
    $two = User::make()->email('members-empty-two@example.com')->makeSuper();
    $two->save();

    expect(BrandUser::query()->count())->toBe(0);

    expect(assigneesIn($this->brandA))->not->toBeEmpty()
        ->and(assigneesIn($this->brandB))->not->toBeEmpty()
        ->and(assigneesIn($this->brandA))->toBe(assigneesIn($this->brandB));
});

it('puts a user back into every brand when their last membership is removed', function (): void {
    $user = User::make()->email('members-restored@example.com')->makeSuper();
    $user->save();

    BrandMembers::attach($user, $this->brandA);
    expect(assigneesIn($this->brandB))->not->toContain((string) $user->id());

    BrandMembers::detach($user, $this->brandA);
    expect(assigneesIn($this->brandB))->toContain((string) $user->id());
});

// ------------------------------------------------------------ the narrowing

it('narrows a user to their own brands once they are assigned anywhere', function (): void {
    $memberOfA = User::make()->email('members-only-a@example.com')->makeSuper();
    $memberOfA->save();
    $bystander = User::make()->email('members-bystander@example.com')->makeSuper();
    $bystander->save();

    BrandMembers::attach($memberOfA, $this->brandA);

    expect(assigneesIn($this->brandA))->toContain((string) $memberOfA->id())
        ->and(assigneesIn($this->brandB))->not->toContain((string) $memberOfA->id())
        // The rule is per person, not per install: the untouched colleague is
        // still offered in both.
        ->and(assigneesIn($this->brandA))->toContain((string) $bystander->id())
        ->and(assigneesIn($this->brandB))->toContain((string) $bystander->id());
});

it('offers a user in each of the several brands they belong to', function (): void {
    $both = User::make()->email('members-both@example.com')->makeSuper();
    $both->save();

    BrandMembers::attach($both, $this->brandA);
    BrandMembers::attach($both, $this->brandB);

    expect(assigneesIn($this->brandA))->toContain((string) $both->id())
        ->and(assigneesIn($this->brandB))->toContain((string) $both->id());
});

it('does not exempt a superuser from the brand filter', function (): void {
    // Holding every permission is not the same as belonging to a brand, and a
    // superuser who has been assigned to one has said which one they work in.
    $super = User::make()->email('members-super@example.com')->makeSuper();
    $super->save();

    BrandMembers::attach($super, $this->brandA);

    expect($super->isSuper())->toBeTrue()
        ->and(assigneesIn($this->brandB))->not->toContain((string) $super->id());
});

it('still requires the LeadHub permission inside the right brand', function (): void {
    // Membership is affiliation, never authorisation. Both filters have to hold.
    $outsider = User::make()->email('members-outsider@example.com');
    $outsider->save();

    BrandMembers::attach($outsider, $this->brandA);

    expect(assigneesIn($this->brandA))->not->toContain((string) $outsider->id());
});

// -------------------------------------------------- the two consuming screens

it('narrows the assignee list the task screens hand to the page', function (): void {
    $memberOfA = User::make()->email('members-task-a@example.com')->makeSuper();
    $memberOfA->save();
    BrandMembers::attach($memberOfA, $this->brandA);

    BrandContext::setCurrent($this->brandB);

    $props = json_decode(
        $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('leadhub.tasks.create'))
            ->getContent(),
        true
    )['props'];

    expect(collect($props['assignableUsers'])->pluck('value'))
        ->not->toContain((string) $memberOfA->id());
});

it('narrows the owner list on the contact screens, which had the same hole', function (): void {
    $memberOfA = User::make()->email('members-owner-a@example.com')->makeSuper();
    $memberOfA->save();
    BrandMembers::attach($memberOfA, $this->brandA);

    BrandContext::setCurrent($this->brandB);

    $props = json_decode(
        $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('leadhub.contacts.create'))
            ->getContent(),
        true
    )['props'];

    expect(collect($props['assignableUsers'])->pluck('value'))
        ->not->toContain((string) $memberOfA->id());
});

it('refuses a write that parks a task on a user of another brand', function (): void {
    // The list is only half of it. Validation runs against the same list
    // (ResolvesCrmReferences::isAssignableUser), so a hand-crafted request
    // cannot reach past the dropdown — and it does so through the model, never
    // through an `exists:` rule.
    $memberOfA = User::make()->email('members-write-a@example.com')->makeSuper();
    $memberOfA->save();
    BrandMembers::attach($memberOfA, $this->brandA);

    BrandContext::setCurrent($this->brandB);
    $contact = Contact::create(['email' => 'members-write@example.com']);

    $this->post(cp_route('leadhub.tasks.store'), [
        'title' => 'Should not cross the boundary',
        'contact_id' => $contact->id,
        'assignee_id' => (string) $memberOfA->id(),
    ])->assertSessionHasErrors('assignee_id');
});

it('accepts a write for an unassigned user, so the upgrade path is not blocked', function (): void {
    // The mirror of the test above, and the one that would break a real
    // install: while nobody is assigned anywhere, every assignment must still
    // go through.
    $unassigned = User::make()->email('members-write-open@example.com')->makeSuper();
    $unassigned->save();

    BrandContext::setCurrent($this->brandB);
    $contact = Contact::create(['email' => 'members-write-open-c@example.com']);

    $this->post(cp_route('leadhub.tasks.store'), [
        'title' => 'Still assignable during the transition',
        'contact_id' => $contact->id,
        'assignee_id' => (string) $unassigned->id(),
    ])->assertSessionHasNoErrors();
});
