<?php

namespace Goldnead\Leadhub\Integrations\Timeline;

use Goldnead\Leadhub\Contracts\TimelineSource;
use Goldnead\Leadhub\Models\Contact;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * What the four sibling readers have in common.
 *
 * Every neighbour is referred to by a **string** class name, never an import.
 * The siblings are `suggest`ed, not required: an import of a class that is not
 * installed is a PHPStan error here and a fatal in a host that only installed
 * LeadHub. `class_exists()` on a string is neither, and it is how the family
 * has always looked across the fence (see payments' `LeadhubBridge`).
 */
abstract class NeighbourSource implements TimelineSource
{
    public function stats(Contact $contact, array $emails): array
    {
        return [];
    }

    public function supersedes(): array
    {
        return [];
    }

    /**
     * `Schema::hasTable()` answers, per table and process. A table does not
     * appear or vanish between two requests of one worker, and the answer is
     * asked several times per screen (available, then every reader).
     *
     * @var array<string, bool>
     */
    protected static array $tables = [];

    /** @var array<string, mixed> */
    protected array $memo = [];

    /**
     * Installed means the class is loadable **and** its table exists. A
     * neighbour that is in `vendor/` but never migrated would otherwise take
     * the contact screen down with a "no such table".
     *
     * @param  list<string>  $classes
     */
    protected function installed(array $classes, string $table): bool
    {
        foreach ($classes as $class) {
            if (! class_exists($class)) {
                return false;
            }
        }

        return static::$tables[$table] ??= $this->tableExists($table);
    }

    protected function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Forget the per-process table answers (tests that create tables late). */
    public static function forgetTables(): void
    {
        static::$tables = [];
    }

    /**
     * One neighbour query per contact and screen: `entries()` and `stats()`
     * read the same rows, and the builder asks for both.
     *
     * @template T
     *
     * @param  \Closure(): T  $load
     * @return T
     */
    protected function remember(Contact $contact, array $emails, \Closure $load): mixed
    {
        $key = spl_object_id($contact).'|'.implode(',', $emails);

        if (! array_key_exists($key, $this->memo)) {
            $this->memo = [$key => $load()];
        }

        return $this->memo[$key];
    }

    /**
     * A CP link into the neighbour, or null when it registers no such route.
     * The neighbour owns its screens; guessing a URL would rot the first time
     * it renames one.
     *
     * @param  array<int|string, mixed>  $parameters
     */
    protected function cpLink(string $route, array $parameters = [], string $query = ''): ?string
    {
        if (! Route::has('statamic.cp.'.$route)) {
            return null;
        }

        $url = cp_route($route, $parameters);

        return is_string($url) && $url !== '' ? $url.$query : null;
    }

    protected function label(string $group, string $key, ?string $fallback = null): string
    {
        $path = 'leadhub::timeline.'.$group.'.'.$key;
        $translated = __($path);

        return $translated === $path ? ($fallback ?? $key) : (string) $translated;
    }
}
