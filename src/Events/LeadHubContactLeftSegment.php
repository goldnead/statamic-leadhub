<?php

namespace Goldnead\Leadhub\Events;

/**
 * Fired when a contact leaves a segment's materialized membership.
 *
 * `metadata` carries at least `segment_handle` and `segment_id`.
 */
class LeadHubContactLeftSegment extends LeadHubEvent {}
