<?php

use Goldnead\Leadhub\Tests\InsightsTestCase;
use Goldnead\Leadhub\Tests\MigrationPathTestCase;
use Goldnead\Leadhub\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// The bridge to the analytics addon needs its stand-ins in place before the
// application boots — the sibling is a `suggest` and is not installed, so the
// contract, the base class and the facade all have to be declared by hand, and
// the facade before the provider's booted() callback looks for it. A directory
// of its own because Pest binds a test case per top-level folder and this one
// needs a different base class than the rest of the suite.
uses(InsightsTestCase::class)->in('Insights');

// The migration path gets a bed of its own: an empty database outside anything
// RefreshDatabase manages, into which an earlier release is installed and then
// migrated forward with rows already in the tables.
uses(MigrationPathTestCase::class)->in('Migrations');
