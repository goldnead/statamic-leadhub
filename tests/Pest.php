<?php

use Goldnead\Leadhub\Tests\MigrationPathTestCase;
use Goldnead\Leadhub\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// The migration path gets a bed of its own: an empty database outside anything
// RefreshDatabase manages, into which an earlier release is installed and then
// migrated forward with rows already in the tables.
uses(MigrationPathTestCase::class)->in('Migrations');
