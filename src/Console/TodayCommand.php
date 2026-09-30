<?php

namespace Goldnead\Leadhub\Console;

use Goldnead\BrandContext\Concerns\RunsForEachBrand;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Task;
use Goldnead\Leadhub\Services\FollowupService;
use Goldnead\Leadhub\Services\TaskService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

/**
 * "What is new and what is due?" — the morning question, for an agent.
 *
 * New contacts of the last 24 hours and 7 days, follow-ups and tasks due
 * today or overdue: a count for each list and its first five. Read only, like
 * {@see ContactLookupCommand}; the lists come from the same repositories and
 * services the dashboard and the follow-up digest read.
 *
 * Under multi-brand one block per brand, or only the brand named by --brand.
 */
class TodayCommand extends Command
{
    use RunsForEachBrand;

    public const LIST_LIMIT = 5;

    protected $signature = 'leadhub:heute
        {--json : Print JSON instead of text}
        {--brand= : Only this brand (handle or id)}';

    protected $description = 'New contacts and what is due today, per brand. Read only.';

    public function handle(ContactRepository $contacts, FollowupService $followups, TaskService $tasks): int
    {
        $filter = $this->option('brand') ?: null;
        $blocks = [];
        $filterHandle = null;

        try {
            $this->forEachBrand(function (?Brand $brand) use ($contacts, $followups, $tasks, $filter, &$blocks, &$filterHandle): int {
                if ($filter !== null && $brand !== null) {
                    $filterHandle = $brand->handle;
                }

                $blocks[] = $this->block($brand, $contacts, $followups, $tasks);

                return self::SUCCESS;
            });
        } catch (ModelNotFoundException) {
            $message = "Unknown brand [{$filter}].";
            $this->option('json') ? $this->printJson(['error' => $message]) : $this->error($message);

            return self::FAILURE;
        }

        $result = [
            'generated_at' => now()->toIso8601String(),
            'multi_brand' => BrandContext::multiBrandEnabled(),
            'brand' => $filterHandle,
            'brands' => $blocks,
        ];

        $this->option('json') ? $this->printJson($result) : $this->printText($result);

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    protected function block(?Brand $brand, ContactRepository $contacts, FollowupService $followups, TaskService $tasks): array
    {
        $recent = $contacts->recent(self::LIST_LIMIT);

        $newSince = function (Carbon $cutoff) use ($recent): array {
            return $recent
                ->filter(fn ($contact) => $contact instanceof Contact && $contact->created_at !== null && $contact->created_at->gte($cutoff))
                ->map(fn (Contact $contact) => [
                    'id' => $contact->id,
                    'uuid' => $contact->uuid,
                    'name' => $contact->displayName(),
                    'email' => $contact->email,
                    'status' => $contact->status,
                    'created_at' => optional($contact->created_at)->toIso8601String(),
                ])
                ->values()
                ->all();
        };

        $followupRow = fn ($followup) => [
            'id' => $followup->id,
            'due_at' => optional($followup->due_at)->toIso8601String(),
            'note' => $followup->note,
            'contact' => $this->contactRef(blank($followup->contact_id) ? null : $contacts->find($followup->contact_id)),
        ];

        $tasksAvailable = (bool) config('leadhub.features.tasks', false)
            && config('leadhub.storage.driver', 'eloquent') === 'eloquent';

        $taskRow = fn (Task $task) => [
            'id' => $task->id,
            'title' => $task->title,
            'priority' => $task->priority,
            'due_at' => optional($task->due_at)->toIso8601String(),
            'contact' => $this->contactRef($task->contact),
        ];

        return [
            'brand' => $brand?->handle,
            'new_contacts' => [
                'last_24h' => [
                    'count' => $contacts->countNewSince(1),
                    'items' => $newSince(now()->subDay()),
                ],
                'last_7d' => [
                    'count' => $contacts->countNewSince(7),
                    'items' => $newSince(now()->subDays(7)),
                ],
            ],
            'followups' => [
                'due_today' => [
                    'count' => $followups->countDueToday(),
                    'items' => $followups->dueToday(self::LIST_LIMIT)->map($followupRow)->values()->all(),
                ],
                'overdue' => [
                    'count' => $followups->countOverdue(),
                    'items' => $followups->overdue(self::LIST_LIMIT)->map($followupRow)->values()->all(),
                ],
            ],
            'tasks' => [
                'available' => $tasksAvailable,
                'due_today' => $tasksAvailable ? [
                    'count' => Task::query()->dueToday()->count(),
                    'items' => $tasks->dueToday(null, self::LIST_LIMIT)->map($taskRow)->values()->all(),
                ] : ['count' => 0, 'items' => []],
                'overdue' => $tasksAvailable ? [
                    'count' => Task::query()->overdue()->count(),
                    'items' => $tasks->overdue(null, self::LIST_LIMIT)->map($taskRow)->values()->all(),
                ] : ['count' => 0, 'items' => []],
            ],
        ];
    }

    /** @return array{id: mixed, uuid: mixed, name: string, email: string|null}|null */
    protected function contactRef(mixed $contact): ?array
    {
        if (! $contact instanceof Contact) {
            return null;
        }

        return [
            'id' => $contact->id,
            'uuid' => $contact->uuid,
            'name' => $contact->displayName(),
            'email' => $contact->email,
        ];
    }

    /** @param array<string, mixed> $result */
    protected function printJson(array $result): void
    {
        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $result */
    protected function printText(array $result): void
    {
        foreach ($result['brands'] as $block) {
            if ($block['brand'] !== null) {
                $this->line("[{$block['brand']}]");
            }

            $new = $block['new_contacts'];
            $this->line("New contacts: {$new['last_24h']['count']} in 24 hours, {$new['last_7d']['count']} in 7 days");
            foreach ($new['last_7d']['items'] as $c) {
                $this->line("  {$c['created_at']}  {$c['name']} <{$c['email']}>");
            }

            $f = $block['followups'];
            $this->line("Follow-ups: {$f['due_today']['count']} due today, {$f['overdue']['count']} overdue");
            foreach ([...$f['overdue']['items'], ...$f['due_today']['items']] as $row) {
                $who = $row['contact'] ? "{$row['contact']['name']} <{$row['contact']['email']}>" : '?';
                $this->line("  {$row['due_at']}  {$who}".($row['note'] ? " — {$row['note']}" : ''));
            }

            $t = $block['tasks'];
            if ($t['available']) {
                $this->line("Tasks: {$t['due_today']['count']} due today, {$t['overdue']['count']} overdue");
                foreach ([...$t['overdue']['items'], ...$t['due_today']['items']] as $row) {
                    $who = $row['contact'] ? " ({$row['contact']['name']})" : '';
                    $this->line("  {$row['due_at']}  {$row['title']}{$who}");
                }
            }

            $this->line('');
        }
    }

    /** A JSON reader must get JSON and nothing else. */
    protected function shouldAnnounceBrand(): bool
    {
        return false;
    }
}
