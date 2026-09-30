<?php

namespace Goldnead\Leadhub\Console;

use Goldnead\BrandContext\Concerns\RunsForEachBrand;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\LeadHubManager;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * "What do we know about Anna?" — for an agent, over SSH.
 *
 * Read only: no write, no event, no log line. The answer is built from the
 * same getters the contact screen uses ({@see LeadHubManager::timelineFor()}
 * and its neighbours), so the command cannot drift from what the CP shows.
 *
 * One hit prints the profile; several print a short list (at most ten) to
 * pick from; none says so. All three exit 0 — "nobody by that name" is an
 * answer, not a failure. Only an unknown --brand exits 1.
 *
 * Under multi-brand every brand is searched unless --brand narrows it, and
 * every match names its brand. The profile is built inside the brand that
 * owns the contact, because tags, follow-ups and tasks are brand-scoped.
 */
class ContactLookupCommand extends Command
{
    use RunsForEachBrand;

    public const MAX_MATCHES = 10;

    protected $signature = 'leadhub:kontakt
        {suche : Email, id, uuid or part of a name}
        {--json : Print JSON instead of text}
        {--timeline=20 : How many timeline entries the profile includes}
        {--brand= : Only this brand (handle or id)}';

    protected $description = 'Look up a contact: profile, timeline, open follow-ups, tasks and deals. Read only.';

    public function handle(LeadHubManager $leadhub, ContactRepository $contacts): int
    {
        $search = trim((string) $this->argument('suche'));
        $filter = $this->option('brand') ?: null;

        /** @var list<array{brand: Brand|null, contact: Contact}> $exact */
        $exact = [];
        /** @var list<array{brand: Brand|null, contact: Contact}> $partial */
        $partial = [];
        $partialTotal = 0;
        $filterHandle = null;

        try {
            $this->forEachBrand(function (?Brand $brand) use ($search, $contacts, &$exact, &$partial, &$partialTotal, &$filterHandle, $filter): int {
                if ($filter !== null && $brand !== null) {
                    $filterHandle = $brand->handle;
                }

                if ($search === '') {
                    return self::SUCCESS;
                }

                $hit = $this->exactHit($search, $contacts);

                if ($hit !== null) {
                    $exact[] = ['brand' => $brand, 'contact' => $hit];

                    return self::SUCCESS;
                }

                [$found, $total] = $this->partialHits($search, $contacts);
                $partialTotal += $total;

                foreach ($found as $contact) {
                    $partial[] = ['brand' => $brand, 'contact' => $contact];
                }

                return self::SUCCESS;
            });
        } catch (ModelNotFoundException) {
            return $this->refuse("Unknown brand [{$filter}].");
        }

        // An exact address or id anywhere beats a name fragment everywhere.
        $candidates = $exact !== [] ? $exact : $partial;
        $total = $exact !== [] ? count($exact) : $partialTotal;

        $matches = array_map(
            fn (array $row) => $this->inBrand($row['brand'], fn () => $this->matchRow($leadhub, $row['contact'], $row['brand'])),
            array_slice($candidates, 0, self::MAX_MATCHES),
        );

        $profile = null;
        if (count($candidates) === 1) {
            $row = $candidates[0];
            $profile = $this->inBrand($row['brand'], fn () => $this->profile($leadhub, $row['contact'], $row['brand']));
        }

        $result = [
            'query' => $search,
            'status' => match (true) {
                $profile !== null => 'found',
                $matches !== [] => 'ambiguous',
                default => 'none',
            },
            'brand' => $filterHandle,
            'total' => $total,
            'matches' => $matches,
            'contact' => $profile,
        ];

        $this->option('json') ? $this->printJson($result) : $this->printText($result);

        return self::SUCCESS;
    }

    /** An id, a uuid or a complete email address — at most one contact. */
    protected function exactHit(string $search, ContactRepository $contacts): ?Contact
    {
        if (str_contains($search, '@')) {
            $normalized = EmailNormalizer::normalize($search);
            $contact = $normalized ? $contacts->findByEmailNormalized($normalized) : null;

            return $contact instanceof Contact ? $contact : null;
        }

        if (ctype_digit($search) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $search)) {
            $contact = $contacts->find($search);

            return $contact instanceof Contact ? $contact : null;
        }

        return null;
    }

    /**
     * Name, email, phone or company containing the search — active contacts
     * first, then archived ones, most recently active first.
     *
     * @return array{0: list<Contact>, 1: int}
     */
    protected function partialHits(string $search, ContactRepository $contacts): array
    {
        $found = [];
        $total = 0;

        foreach ([false, true] as $archived) {
            $page = $contacts->paginate([
                'search' => $search,
                'archived' => $archived,
                'sort' => 'last_activity_at',
                'direction' => 'desc',
            ], self::MAX_MATCHES, 1);

            $total += $page->total();

            foreach ($page->items() as $contact) {
                if ($contact instanceof Contact) {
                    $found[] = $contact;
                }
            }
        }

        return [array_slice($found, 0, self::MAX_MATCHES), $total];
    }

    /** @return array<string, mixed> */
    protected function matchRow(LeadHubManager $leadhub, Contact $contact, ?Brand $brand): array
    {
        return [
            'id' => $contact->id,
            'uuid' => $contact->uuid,
            'name' => $contact->displayName(),
            'email' => $contact->email,
            'tags' => $leadhub->present($contact)['tags'],
            'last_activity_at' => optional($contact->last_activity_at)->toIso8601String(),
            'archived' => $contact->archived_at !== null,
            'brand' => $brand?->handle,
        ];
    }

    /** @return array<string, mixed> */
    protected function profile(LeadHubManager $leadhub, Contact $contact, ?Brand $brand): array
    {
        $limit = max(0, (int) $this->option('timeline'));
        $timeline = $leadhub->timelineFor($contact, max($limit, 1)) ?? [
            'entries' => [], 'total' => 0, 'sources' => [], 'failed' => [], 'stats' => [],
        ];

        // The screen's links and raw payloads stay behind: a relative CP URL
        // means nothing over SSH, and a payload is whatever a form posted.
        $entries = array_map(fn (array $entry) => [
            'id' => $entry['id'] ?? null,
            'source' => $entry['source'] ?? null,
            'kind' => $entry['kind'] ?? null,
            'at' => $entry['at'] ?? null,
            'summary' => $entry['summary'] ?? null,
            'badge' => $entry['badge'] ?? null,
            'amount' => $entry['amount'] ?? null,
            'detail' => $entry['detail'] ?? [],
            'actor' => $entry['actor'] ?? null,
        ], array_slice($timeline['entries'], 0, $limit));

        return $leadhub->present($contact) + [
            'brand' => $brand?->handle,
            'archived_at' => optional($contact->archived_at)->toIso8601String(),
            'revenue' => $leadhub->revenueFor($contact->id, 20),
            'timeline' => [
                'entries' => $entries,
                'total' => $timeline['total'],
                'sources' => $timeline['sources'],
                'failed' => $timeline['failed'],
                'stats' => $timeline['stats'],
            ],
            'followups' => $leadhub->followupsFor($contact),
            'tasks' => $leadhub->tasksFor($contact, openOnly: true),
            'opportunities' => $leadhub->opportunitiesFor($contact, openOnly: true),
        ];
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    protected function inBrand(?Brand $brand, \Closure $callback): mixed
    {
        return $brand !== null ? BrandContext::runFor($brand, $callback) : $callback();
    }

    /** @param array<string, mixed> $result */
    protected function printJson(array $result): void
    {
        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $result */
    protected function printText(array $result): void
    {
        if ($result['status'] === 'none') {
            $this->line("No contact matches \"{$result['query']}\".");

            return;
        }

        if ($result['status'] === 'ambiguous') {
            $this->line("{$result['total']} contacts match \"{$result['query']}\":");

            foreach ($result['matches'] as $m) {
                $brand = $m['brand'] ? " [{$m['brand']}]" : '';
                $tags = $m['tags'] ? ' #'.implode(' #', $m['tags']) : '';
                $archived = $m['archived'] ? ' (archived)' : '';
                $this->line("  {$m['id']}  {$m['name']} <{$m['email']}>{$tags}{$archived}{$brand}  last activity: ".($m['last_activity_at'] ?? '-'));
            }

            return;
        }

        $c = $result['contact'];
        $this->line("{$c['full_name']} <{$c['email']}>".($c['brand'] ? " [{$c['brand']}]" : ''));
        $this->line("  id {$c['id']} · uuid {$c['uuid']} · status {$c['status']}".($c['archived_at'] ? ' · archived' : ''));

        foreach (['phone' => 'phone', 'company' => 'company', 'source' => 'source'] as $key => $label) {
            if (! empty($c[$key])) {
                $this->line("  {$label}: {$c[$key]}");
            }
        }

        $this->line('  tags: '.($c['tags'] ? implode(', ', $c['tags']) : '-'));
        $this->line('  created: '.($c['created_at'] ?? '-').' · last activity: '.($c['last_activity_at'] ?? '-'));
        $this->line(sprintf('  purchases: %d · net %s %s', $c['purchase_count'], number_format($c['net_revenue_cent'] / 100, 2, ',', '.'), $c['revenue_currency'] ?? ''));

        $this->section('Follow-ups', array_map(fn ($f) => ($f['due_at'] ?? '-').($f['is_overdue'] ? ' (overdue)' : '').($f['note'] ? " — {$f['note']}" : ''), $c['followups']));
        $this->section('Open tasks', array_map(fn ($t) => ($t['due_at'] ?? 'no date').' — '.$t['title'].($t['is_overdue'] ? ' (overdue)' : ''), $c['tasks']));
        $this->section('Open deals', array_map(fn ($o) => $o['title'].' — '.($o['pipeline_name'] ?? '?').' / '.($o['stage_name'] ?? '?').($o['value_estimate'] !== null ? ' — '.$o['value_estimate'] : ''), $c['opportunities']));
        $this->section('Purchases', array_map(fn ($r) => ($r['occurred_at'] ?? '-').' — '.number_format($r['net_cent'] / 100, 2, ',', '.').' '.$r['currency'].' — '.$r['reference'], $c['revenue']));
        $this->section("Timeline ({$c['timeline']['total']})", array_map(fn ($e) => ($e['at'] ?? '-').' ['.$e['source'].'] '.$e['summary'], $c['timeline']['entries']));
    }

    /** @param list<string> $lines */
    protected function section(string $title, array $lines): void
    {
        $this->line('');
        $this->line($title.':'.($lines === [] ? ' -' : ''));

        foreach ($lines as $line) {
            $this->line('  '.$line);
        }
    }

    protected function refuse(string $message): int
    {
        $this->option('json')
            ? $this->printJson(['error' => $message])
            : $this->error($message);

        return self::FAILURE;
    }

    /** A JSON reader must get JSON and nothing else. */
    protected function shouldAnnounceBrand(): bool
    {
        return false;
    }
}
