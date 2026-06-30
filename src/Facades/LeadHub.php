<?php

namespace Goldnead\Leadhub\Facades;

use Goldnead\Leadhub\LeadHubManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array statuses()
 * @method static array tags()
 * @method static array|null find(int|string $id)
 * @method static array|null findByEmail(string $email)
 * @method static array create(array $attributes)
 * @method static array update(int|string $id, array $attributes)
 * @method static array addTag(int|string $id, string $tag)
 * @method static array removeTag(int|string $id, string $tag)
 * @method static array changeStatus(int|string $id, string $status)
 * @method static array addNote(int|string $id, string $body, ?string $userId = null)
 * @method static array createFollowUp(int|string $id, array $data)
 * @method static array completeFollowUp(int|string $id, int|string $followUpId)
 * @method static \Goldnead\Leadhub\Models\Event|null ingest(\Goldnead\Leadhub\Support\SourceEvent|array $event)
 * @method static void registerSourceProjector(\Goldnead\Leadhub\Contracts\SourceProjector $projector)
 * @method static \Goldnead\Leadhub\Models\Event|null projectAndIngest(mixed $model)
 *
 * @see \Goldnead\Leadhub\LeadHubManager
 */
class LeadHub extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LeadHubManager::class;
    }
}
