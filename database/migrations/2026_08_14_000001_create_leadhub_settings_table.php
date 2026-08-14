<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings that an operator changed from the Control Panel.
 *
 * The table holds *overrides only*, one row per changed key — not a copy of the
 * config file. That is what makes an untouched install behave exactly as it did
 * before this table existed, and what lets a key that is never edited keep
 * following the package default when the default changes in a later release. A
 * settings row that mirrored every key would freeze the shipped defaults at
 * whatever they were on the day somebody first opened the screen.
 *
 * Deliberately **not** brand-scoped, unlike every other table in this addon.
 * These are properties of the installation: which disk exports are written to,
 * which field names are redacted before a payload is stored, whether the tasks
 * module exists at all. A feature flag that differed per brand would mean the
 * navigation, the routes and the scheduled commands disagreed about which
 * modules are installed, with nothing on screen to say so.
 *
 * `key` is the dotted path under `leadhub.` (`exports.queue_threshold`), so a
 * row says where it applies without a mapping table in between. `value` is JSON
 * because the values are not all scalars — `timeline_payload_redaction` is a
 * list — and because the "back to default" rule is a strict comparison against
 * the config file, which an integer stored as a string would never satisfy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadhub_settings', function (Blueprint $table) {
            $table->id();
            // 191, not the default 255: this addon supports MySQL installs on
            // utf8mb4 with the 767-byte InnoDB index limit, where a unique index
            // over a 255-character column does not fit. The longest path this
            // table will ever hold is under 60 characters.
            $table->string('key', 191)->unique();
            $table->json('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadhub_settings');
    }
};
