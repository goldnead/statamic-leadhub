<?php

namespace Goldnead\Leadhub\Repositories\Eloquent;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Models\Contact;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EloquentContactRepository implements ContactRepository
{
    public function find(int|string $id): ?Contact
    {
        // Accept either the auto-increment int id or a UUID string.
        // Routes pass UUIDs (so the flat-file driver can also resolve them);
        // this method handles both forms transparently.
        if (is_string($id) && ! ctype_digit($id)) {
            return $this->findByUuid($id);
        }

        return Contact::query()->find($id);
    }

    public function findByUuid(string $uuid): ?Contact
    {
        return Contact::query()->where('uuid', $uuid)->first();
    }

    public function findByEmailNormalized(string $emailNormalized): ?Contact
    {
        return Contact::query()
            ->where('email_normalized', $emailNormalized)
            ->first();
    }

    public function findByPhoneNormalized(string $phoneNormalized): ?Contact
    {
        return Contact::query()
            ->where('phone_normalized', $phoneNormalized)
            ->first();
    }

    public function create(array $attributes): Contact
    {
        return Contact::query()->create($attributes);
    }

    public function save(Contact $contact): Contact
    {
        $contact->save();

        return $contact;
    }

    public function delete(Contact $contact): void
    {
        $contact->delete();
    }

    public function archive(Contact $contact): void
    {
        $contact->archived_at = now();
        $contact->save();
    }

    public function restore(Contact $contact): void
    {
        $contact->archived_at = null;
        $contact->save();
    }

    public function touchActivity(Contact $contact): void
    {
        $contact->last_activity_at = now();
        $contact->save();
    }

    public function paginate(array $filters = [], int $perPage = 25, int $page = 1): LengthAwarePaginator
    {
        $query = Contact::query()->with(['tags', 'followups']);

        $query = ! empty($filters['archived']) ? $query->archived() : $query->active();

        if (! empty($filters['status'])) {
            $query->withStatus($filters['status']);
        }

        if (! empty($filters['source_form'])) {
            $query->where('source_form', $filters['source_form']);
        }

        if (! empty($filters['tag_id'])) {
            $query->whereHas('tags', fn (Builder $q) => $q->where('leadhub_tags.id', $filters['tag_id']));
        }

        if (! empty($filters['assigned_to'])) {
            $filters['assigned_to'] === 'none'
                ? $query->whereNull('assigned_to')
                : $query->where('assigned_to', $filters['assigned_to']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        if (! empty($filters['has_followup'])) {
            switch ($filters['has_followup']) {
                case 'has':
                    $query->whereHas('followups', fn (Builder $q) => $q->whereNull('completed_at'));
                    break;
                case 'today':
                    $query->whereHas('followups', fn (Builder $q) => $q->dueToday());
                    break;
                case 'overdue':
                    $query->whereHas('followups', fn (Builder $q) => $q->overdue());
                    break;
            }
        }

        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // Engagement score range. `!== null` rather than `! empty()`: a floor of
        // 0 is a legitimate filter (it is the score every contact starts at)
        // and empty() would throw it away.
        if (($filters['score_min'] ?? null) !== null && $filters['score_min'] !== '') {
            $query->where('engagement_score', '>=', (int) $filters['score_min']);
        }
        if (($filters['score_max'] ?? null) !== null && $filters['score_max'] !== '') {
            $query->where('engagement_score', '<=', (int) $filters['score_max']);
        }

        $sort = in_array($filters['sort'] ?? null, ['created_at', 'last_activity_at', 'status', 'engagement_score'], true)
            ? $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $direction);

        return $query->paginate($perPage, page: $page);
    }

    public function recent(int $limit = 5): Collection
    {
        return Contact::query()
            ->active()
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function countNewSince(int $days): int
    {
        return Contact::query()
            ->active()
            ->where('created_at', '>=', now()->subDays($days))
            ->count();
    }

    public function countByStatus(): array
    {
        return Contact::query()
            ->active()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();
    }

    public function countWithStatus(string $status): int
    {
        return Contact::query()->active()->withStatus($status)->count();
    }

    public function distinctSources(): array
    {
        return Contact::query()
            ->active()
            ->whereNotNull('source_form')
            ->distinct()
            ->pluck('source_form')
            ->all();
    }
}
