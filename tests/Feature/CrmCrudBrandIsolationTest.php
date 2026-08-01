<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Facades\BrandMembers;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Company;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Opportunity;
use Goldnead\Leadhub\Models\Pipeline;
use Goldnead\Leadhub\Models\Task;
use Goldnead\Leadhub\Support\UserDirectory;
use Statamic\Facades\User;

/**
 * The new CRM write surface, seen from the wrong brand.
 *
 * Every screen added in v1.7.0 is a new way to reach a record by id. A form is
 * exactly the place where a global-scope hole becomes a data leak with a
 * friendly interface, so each module is asked the same three questions from
 * brand B about a record of brand A: is it in the list, can the form open it,
 * can a write reach it.
 *
 * The brand is switched through BrandContext before the request, which is
 * exactly what brand-context's CP middleware does on a real request — the
 * middleware group is not applied to addon CP routes under orchestra/testbench,
 * so driving it via `?brand=<handle>` here would silently test the default
 * brand and every assertion below would pass for the wrong reason.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('The CRM-core modules are eloquent-only.');
    }

    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    config()->set('leadhub.features.companies', true);
    config()->set('leadhub.features.tasks', true);
    config()->set('leadhub.features.pipelines', true);

    $this->brandA = Brand::create(['handle' => 'crm-a', 'name' => 'CRM A']);
    $this->brandB = Brand::create(['handle' => 'crm-b', 'name' => 'CRM B']);

    $this->user = User::make()->email('crm-brands@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    // Everything below lives in brand A.
    [$this->company, $this->contact, $this->task, $this->opportunity, $this->pipeline]
        = BrandContext::runFor($this->brandA, function () {
            $company = Company::create(['name' => 'Alpha GmbH']);
            $contact = Contact::create(['email' => 'alpha@example.com', 'first_name' => 'Alma']);
            $task = Task::create([
                'title' => 'Alpha task',
                'contact_id' => $contact->id,
                'status' => Task::STATUS_OPEN,
                'assignee_id' => (string) $this->user->id(),
            ]);

            LeadHub::createPipeline('Alpha Sales', [['name' => 'New']]);
            $pipeline = Pipeline::query()->where('slug', 'alpha-sales')->firstOrFail();

            $opportunity = Opportunity::create([
                'contact_id' => $contact->id,
                'pipeline_id' => $pipeline->id,
                'stage_id' => $pipeline->stages()->first()->id,
                'title' => 'Alpha deal',
                'status' => Opportunity::STATUS_OPEN,
            ]);

            return [$company, $contact, $task, $opportunity, $pipeline];
        });
});

/**
 * The URL of a CP route, with brand B made current first — the state the CP
 * middleware would have established for a user who switched brands.
 */
function asBrandB($test, string $route, mixed $params = [])
{
    BrandContext::setCurrent($test->brandB);

    return cp_route($route, $params);
}

function inertiaProps($response): array
{
    return json_decode($response->getContent(), true)['props'] ?? [];
}

// ------------------------------------------------------------------ Companies

it('hides brand A companies from brand B and refuses every form that names one', function (): void {
    $index = $this->withHeaders(['X-Inertia' => 'true'])->get(asBrandB($this, 'leadhub.companies.index'));
    expect(inertiaProps($index)['companies'])->toBe([]);

    $this->withHeaders(['X-Inertia' => 'true'])
        ->get(asBrandB($this, 'leadhub.companies.edit', $this->company->id))
        ->assertStatus(404);

    $this->patch(asBrandB($this, 'leadhub.companies.update', $this->company->id), ['name' => 'Hijacked'])
        ->assertStatus(404);

    $this->delete(asBrandB($this, 'leadhub.companies.destroy', $this->company->id))
        ->assertStatus(404);

    expect(BrandContext::withoutBrandScope(fn () => Company::query()->find($this->company->id)->name))
        ->toBe('Alpha GmbH');
});

it('stamps a company created from brand B onto brand B', function (): void {
    $this->post(asBrandB($this, 'leadhub.companies.store'), ['name' => 'Beta GmbH'])
        ->assertSessionHasNoErrors();

    $created = BrandContext::withoutBrandScope(fn () => Company::query()->where('name', 'Beta GmbH')->first());

    expect($created->brand_id)->toBe($this->brandB->id);
});

it('lets the same company name exist once per brand', function (): void {
    // The duplicate check is brand-scoped, so brand B is not blocked by a name
    // it cannot even see.
    $this->post(asBrandB($this, 'leadhub.companies.store'), ['name' => 'Alpha GmbH'])
        ->assertSessionHasNoErrors();

    expect(BrandContext::withoutBrandScope(fn () => Company::query()->where('name', 'Alpha GmbH')->count()))
        ->toBe(2);
});

// ---------------------------------------------------------------------- Tasks

it('hides brand A tasks from brand B and refuses every form that names one', function (): void {
    $index = $this->withHeaders(['X-Inertia' => 'true'])->get(asBrandB($this, 'leadhub.tasks.index'));
    expect(inertiaProps($index)['tasks'])->toBe([]);

    $this->withHeaders(['X-Inertia' => 'true'])
        ->get(asBrandB($this, 'leadhub.tasks.edit', $this->task->id))
        ->assertStatus(404);

    $this->patch(asBrandB($this, 'leadhub.tasks.update', $this->task->id), ['title' => 'Hijacked'])
        ->assertStatus(404);

    $this->delete(asBrandB($this, 'leadhub.tasks.destroy', $this->task->id))
        ->assertStatus(404);

    expect(BrandContext::withoutBrandScope(fn () => Task::query()->find($this->task->id)->title))
        ->toBe('Alpha task');
});

it('refuses a task in brand B that points at a contact of brand A', function (): void {
    // This is the check Laravel's `exists:` rule would have got wrong: it
    // compiles to a raw query and never sees the brand scope.
    $this->post(asBrandB($this, 'leadhub.tasks.store'), [
        'title' => 'Cross-brand task',
        'contact_id' => $this->contact->id,
    ])->assertSessionHasErrors('contact_id');

    expect(BrandContext::withoutBrandScope(fn () => Task::query()->count()))->toBe(1);
});

it('does not let an assignee filter reach across brands', function (): void {
    // The same user account exists in both brands — users are global. What
    // must not cross is the work: brand B asking for this user's tasks must
    // not be shown brand A's.
    BrandContext::setCurrent($this->brandB);

    $index = $this->withHeaders(['X-Inertia' => 'true'])->get(
        cp_route('leadhub.tasks.index').'?assignee_id='.$this->user->id()
    );

    expect(inertiaProps($index)['tasks'])->toBe([]);

    $mine = $this->withHeaders(['X-Inertia' => 'true'])->get(
        cp_route('leadhub.tasks.index').'?mine=1'
    );

    expect(inertiaProps($mine)['tasks'])->toBe([]);
});

// -------------------------------------------------------------- Opportunities

it('hides brand A opportunities from brand B and refuses every form that names one', function (): void {
    $board = $this->withHeaders(['X-Inertia' => 'true'])->get(asBrandB($this, 'leadhub.pipelines.board'));
    $props = inertiaProps($board);

    // Brand B has no pipeline at all, so the board is the empty state — which
    // is itself the assertion: brand A's pipeline is not visible here.
    expect($props['pipelines'])->toBe([]);

    $this->withHeaders(['X-Inertia' => 'true'])
        ->get(asBrandB($this, 'leadhub.pipelines.opportunities.edit', $this->opportunity->id))
        ->assertStatus(404);

    $this->patch(asBrandB($this, 'leadhub.pipelines.opportunities.update', $this->opportunity->id), ['title' => 'Hijacked'])
        ->assertStatus(404);

    $this->delete(asBrandB($this, 'leadhub.pipelines.opportunities.destroy', $this->opportunity->id))
        ->assertStatus(404);

    expect(BrandContext::withoutBrandScope(fn () => Opportunity::query()->find($this->opportunity->id)->title))
        ->toBe('Alpha deal');
});

it('refuses an opportunity in brand B built on brand A records', function (): void {
    $response = $this->post(asBrandB($this, 'leadhub.pipelines.opportunities.store'), [
        'contact_id' => $this->contact->id,
        'pipeline_id' => $this->pipeline->id,
    ]);

    $response->assertSessionHasErrors(['contact_id', 'pipeline_id']);

    expect(BrandContext::withoutBrandScope(fn () => Opportunity::query()->count()))->toBe(1);
});

// ------------------------------------------------------------- Contact picker

it('does not offer brand A contacts in the brand B picker', function (): void {
    BrandContext::runFor($this->brandB, fn () => Contact::create([
        'email' => 'beta@example.com',
        'first_name' => 'Bea',
    ]));

    BrandContext::setCurrent($this->brandB);

    $response = $this->get(cp_route('leadhub.contacts.options'));
    $response->assertStatus(200);

    $values = collect($response->json('options'))->pluck('value');

    expect($values)->not->toContain((string) $this->contact->id)
        ->and($values)->toHaveCount(1);
});

// ------------------------------------------------------------- Assignee list

/**
 * The assignee list, now that users carry a brand.
 *
 * This test used to pin the opposite: it asserted that both brands were offered
 * the same names, because there was nothing to narrow them with. brand-context
 * 1.5.0 shipped `brand_user` and `BrandMembers`, so `Support\UserDirectory`
 * asks two questions instead of one — the LeadHub permission and the brand
 * membership — and the pin turns into its mirror image.
 *
 * The isolation is the smaller half. The half that decides whether an upgrade
 * is survivable is the transition rule, and it lives in
 * tests/Feature/AssigneeBrandMembershipTest.php in full. What stays here is the
 * one line that belongs to this file: from brand B, a user of brand A is not on
 * offer.
 */
it('does not offer a user of another brand as an assignee', function (): void {
    $inBrandA = User::make()->email('assignable-in-a@example.com')->makeSuper();
    $inBrandA->save();

    $unassigned = User::make()->email('assignable-everywhere@example.com')->makeSuper();
    $unassigned->save();

    $outsider = User::make()->email('outsider@example.com');
    $outsider->save();

    BrandMembers::attach($inBrandA, $this->brandA);

    $listFor = function ($brand) {
        BrandContext::setCurrent($brand);

        return collect(app(UserDirectory::class)->assignable())
            ->pluck('value')->sort()->values()->all();
    };

    $a = $listFor($this->brandA);
    $b = $listFor($this->brandB);

    expect($a)->toContain((string) $inBrandA->id())
        ->and($b)->not->toContain((string) $inBrandA->id())
        // Nobody assigned this one anywhere, so the transition rule keeps them
        // in both — the upgrade path, asserted where it would be missed.
        ->and($a)->toContain((string) $unassigned->id())
        ->and($b)->toContain((string) $unassigned->id())
        // The boundary that held all along: no LeadHub permission, no
        // assignment, in either brand.
        ->and($a)->not->toContain((string) $outsider->id())
        ->and($b)->not->toContain((string) $outsider->id());
});
