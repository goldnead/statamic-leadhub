<?php

namespace Goldnead\Leadhub\Tests\Fixtures\Timeline;

use Goldnead\Leadhub\Contracts\TimelineSource;
use Goldnead\Leadhub\Integrations\Entitlements\AccessGranter;
use Goldnead\Leadhub\Integrations\Timeline\BookingSource;
use Goldnead\Leadhub\Integrations\Timeline\ConsentSource;
use Goldnead\Leadhub\Integrations\Timeline\EntitlementsSource;
use Goldnead\Leadhub\Integrations\Timeline\PaymentsSource;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\Timeline\TimelineEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stand-ins for the sibling addons' tables, so the readers can be exercised
 * against real rows without the siblings installed.
 *
 * The suite runs in exactly the state the addon must survive: none of
 * payments, entitlements, booking or consent is in vendor/. So the readers are
 * subclassed here with their **model hook** pointed at a test model on a table
 * of the same shape — the query, the matching and the mapping are the code
 * under test, the neighbour's class is not. The real classes are never
 * declared, which keeps the "neighbour is missing" tests honest in the same
 * process.
 */
final class NeighbourStubs
{
    public static function migrate(): void
    {
        Schema::dropIfExists('payment_items');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('entitlements');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('consent_records');

        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->string('provider_id')->nullable();
            $t->string('product')->nullable();
            $t->unsignedInteger('amount_cent')->default(0);
            $t->string('currency', 3)->default('EUR');
            $t->string('status', 32)->default('paid');
            $t->string('email')->nullable();
            $t->unsignedInteger('refunded_cent')->default(0);
            $t->timestamp('refunded_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });

        Schema::create('payment_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('payment_id');
            $t->string('product');
            $t->string('name');
            $t->unsignedInteger('amount_cent')->default(0);
            $t->unsignedSmallInteger('quantity')->default(1);
            $t->timestamps();
        });

        Schema::create('entitlements', function (Blueprint $t) {
            $t->id();
            $t->string('subject_type');
            $t->string('subject_id');
            $t->string('product_slug');
            $t->string('source')->default('manual');
            $t->string('source_ref')->default('');
            $t->string('status', 16)->default('active');
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->string('revoked_reason')->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();
        });

        Schema::create('bookings', function (Blueprint $t) {
            $t->id();
            $t->string('endpoint', 64);
            $t->string('status', 32)->default('booked');
            $t->timestamp('scheduled_at')->nullable();
            $t->unsignedInteger('duration_minutes')->nullable();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('meeting_url')->nullable();
            $t->timestamps();
        });

        Schema::create('consent_records', function (Blueprint $t) {
            $t->id();
            $t->string('consent_id', 64);
            $t->unsignedInteger('version');
            $t->json('granted');
            $t->string('how', 32);
            $t->string('site', 64)->nullable();
            $t->timestamp('decided_at');
            $t->timestamp('created_at')->useCurrent();
        });
    }
}

class StubPayment extends Model
{
    protected $table = 'payments';

    protected $guarded = [];

    protected $casts = ['paid_at' => 'datetime', 'refunded_at' => 'datetime'];

    public function items(): HasMany
    {
        return $this->hasMany(StubPaymentItem::class, 'payment_id');
    }
}

class StubPaymentItem extends Model
{
    protected $table = 'payment_items';

    protected $guarded = [];
}

class StubEntitlement extends Model
{
    protected $table = 'entitlements';

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}

class StubBooking extends Model
{
    protected $table = 'bookings';

    protected $guarded = [];

    protected $casts = ['scheduled_at' => 'datetime'];
}

class StubConsentRecord extends Model
{
    protected $table = 'consent_records';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['granted' => 'array', 'decided_at' => 'datetime', 'created_at' => 'datetime'];
}

class StubPaymentsSource extends PaymentsSource
{
    protected const MODEL = StubPayment::class;

    public function available(): bool
    {
        return true;
    }
}

class StubEntitlementsSource extends EntitlementsSource
{
    public function available(): bool
    {
        return true;
    }

    protected function query()
    {
        return StubEntitlement::query();
    }

    /** The column verdict, standing in for the manager's. */
    protected function state(object $grant): string
    {
        if ($grant->revoked_at) {
            return 'revoked';
        }
        if ($grant->expires_at && $grant->expires_at->isPast()) {
            return 'expired';
        }

        return (string) $grant->status;
    }
}

class StubBookingSource extends BookingSource
{
    protected const MODEL = StubBooking::class;

    public function available(): bool
    {
        return true;
    }
}

class StubConsentSource extends ConsentSource
{
    protected const MODEL = StubConsentRecord::class;

    public function available(): bool
    {
        return true;
    }
}

/** A source under test's control: fixed entries, fixed stats, may throw. */
class ScriptedSource implements TimelineSource
{
    /**
     * @param  list<TimelineEntry>  $entries
     * @param  array<string, mixed>  $stats
     * @param  list<string>  $supersedes
     */
    public function __construct(
        protected string $key,
        protected array $entries = [],
        protected array $stats = [],
        protected array $supersedes = [],
        protected bool $available = true,
        protected ?\Throwable $throws = null,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function available(): bool
    {
        return $this->available;
    }

    public function entries(Contact $contact, array $emails): array
    {
        if ($this->throws) {
            throw $this->throws;
        }

        return $this->entries;
    }

    public function stats(Contact $contact, array $emails): array
    {
        return $this->stats;
    }

    public function supersedes(): array
    {
        return $this->supersedes;
    }
}

/** The granter with the neighbour's write side replaced by a recorder. */
class RecordingGranter extends AccessGranter
{
    /** @var list<array<string, mixed>> */
    public array $writes = [];

    /** @param  list<array{value: string, label: string, slugs: list<string>}>  $options */
    public function __construct(protected array $scriptedOptions = [], protected bool $isAvailable = true) {}

    public function available(): bool
    {
        return $this->isAvailable;
    }

    public function options(): array
    {
        return $this->scriptedOptions;
    }

    protected function subjectFor(string $email): mixed
    {
        return ['email', $email];
    }

    protected function actorFor($user): mixed
    {
        return $user ? (string) $user->getAuthIdentifier() : null;
    }

    protected function write(mixed $subject, string $slug, string $sourceRef, array $meta, mixed $actor): object
    {
        $this->writes[] = compact('subject', 'slug', 'sourceRef', 'meta', 'actor');

        return new class(count($this->writes))
        {
            public function __construct(public int $id) {}

            public function getKey(): int
            {
                return $this->id;
            }
        };
    }
}
