<?php

namespace Goldnead\Leadhub\Contracts;

use Goldnead\Leadhub\Support\SourceEvent;

/**
 * Maps a host-application model (a purchase, booking, login token, …) into a
 * normalized SourceEvent that LeadHub can ingest. Register implementations via
 * LeadHub::registerSourceProjector().
 */
interface SourceProjector
{
    /** The fully-qualified class name this projector handles. */
    public function sourceType(): string;

    /** Build a SourceEvent from the given model, or null to skip ingestion. */
    public function project(mixed $model): ?SourceEvent;
}
