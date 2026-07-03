<?php

namespace Goldnead\Leadhub\Tests\Feature;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Tests\Fixtures\PlainAuthUser;
use Goldnead\Leadhub\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * Regression: on Statamic sites using the *eloquent* users repository, the
 * authenticated user is a plain Eloquent model (e.g. App\Models\User) that
 * has neither Statamic's hasPermission()/isSuper() nor an id() method.
 * Calling those on $request->user() blew up every LeadHub CP page with a
 * BadMethodCallException. Only the file driver (where the auth user IS a
 * Statamic user) worked — which is why the regular suite never caught it.
 *
 * These tests run with statamic.users.repository=eloquent and a plain
 * Authenticatable model — the exact consuming-site setup that crashed.
 * The addon must go through Laravel's Gate ($user->can()), which Statamic
 * hooks via Gate::after (resolving User::fromUser() and short-circuiting
 * supers) so it works for BOTH user drivers.
 */
class EloquentUserCompatTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic.users.repository', 'eloquent');
        $app['config']->set('statamic.users.database', 'sqlite');
        $app['config']->set('auth.providers.users.model', PlainAuthUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('users')) {
            Schema::create('users', function ($table) {
                $table->increments('id');
                $table->string('name')->nullable();
                $table->string('email')->unique();
                $table->string('password')->nullable();
                $table->boolean('super')->default(false);
                $table->rememberToken();
                $table->timestamps();
            });
        }

        // Pivot tables Statamic's eloquent user driver queries when it
        // resolves a user's roles/groups for permission checks.
        if (! Schema::hasTable('role_user')) {
            Schema::create('role_user', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('user_id');
                $table->string('role_id');
            });
        }

        if (! Schema::hasTable('group_user')) {
            Schema::create('group_user', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('user_id');
                $table->string('group_id');
            });
        }
    }

    private function makeUser(bool $super): PlainAuthUser
    {
        return PlainAuthUser::create([
            'name' => 'Plain User',
            'email' => ($super ? 'super' : 'regular').'@example.com',
            'super' => $super,
        ]);
    }

    public function test_dashboard_renders_for_super_eloquent_user(): void
    {
        $this->actingAs($this->makeUser(super: true));

        $response = $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('leadhub.dashboard'));

        $response->assertStatus(200);
    }

    public function test_contacts_index_renders_for_super_eloquent_user(): void
    {
        $this->actingAs($this->makeUser(super: true));

        $response = $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('leadhub.contacts.index'));

        $response->assertStatus(200);
    }

    public function test_contact_detail_renders_for_super_eloquent_user(): void
    {
        $contact = app(ContactRepository::class)->create([
            'email' => 'compat@example.com',
            'email_normalized' => 'compat@example.com',
            'first_name' => 'Compat',
            'status' => 'new',
        ]);

        $this->actingAs($this->makeUser(super: true));

        $response = $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('leadhub.contacts.show', $contact->uuid));

        $response->assertStatus(200);
    }

    public function test_tags_index_renders_for_super_eloquent_user(): void
    {
        $this->actingAs($this->makeUser(super: true));

        $response = $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('leadhub.tags.index'));

        $response->assertStatus(200);
    }

    public function test_segments_index_renders_for_super_eloquent_user(): void
    {
        $this->actingAs($this->makeUser(super: true));

        $response = $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('leadhub.segments.index'));

        $response->assertStatus(200);
    }

    public function test_segments_create_renders_for_super_eloquent_user(): void
    {
        $this->actingAs($this->makeUser(super: true));

        $response = $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('leadhub.segments.create'));

        $response->assertStatus(200);
    }

    public function test_unprivileged_eloquent_user_gets_403_on_segments(): void
    {
        $this->actingAs($this->makeUser(super: false));

        $response = $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('leadhub.segments.index'));

        $response->assertStatus(403);
    }

    public function test_unprivileged_eloquent_user_gets_403_not_500(): void
    {
        $this->actingAs($this->makeUser(super: false));

        $response = $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('leadhub.dashboard'));

        $response->assertStatus(403);
    }

    public function test_policy_accepts_plain_eloquent_user(): void
    {
        $this->assertTrue(
            Gate::forUser($this->makeUser(super: true))->allows('viewAny', Contact::class)
        );

        $this->assertFalse(
            Gate::forUser($this->makeUser(super: false))->allows('viewAny', Contact::class)
        );
    }
}
