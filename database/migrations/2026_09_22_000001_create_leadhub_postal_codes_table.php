<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Postal codes with a point on the map.
 *
 * A radius condition needs two coordinates, and a contact only ever gives a
 * postal code. This table is the translation between the two, filled from open
 * data by `leadhub:postal-codes` rather than looked up per send — a lookup
 * service that is down at send time would silently shrink a segment.
 *
 * Not a per-brand table. A postal code means the same thing for every brand on
 * the installation, and stamping a brand on it would mean importing the same
 * 10.813 German rows once per brand.
 *
 * `postal_code` is a string, and the pair (country, postal_code) is the key:
 * Austria's 1070 and Germany's 10707 are different places that a five-digit
 * integer assumption would blur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadhub_postal_codes', function (Blueprint $table) {
            $table->id();
            $table->string('country', 2);
            $table->string('postal_code', 16);
            $table->string('place')->nullable();
            $table->string('region')->nullable();

            // decimal(10,7) is roughly a centimetre — far more than a postal
            // district's centre deserves, and exact enough that a radius never
            // rounds a place across a border it was near.
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            $table->timestamps();

            $table->unique(['country', 'postal_code'], 'leadhub_postal_country_code_unq');

            // A radius query draws a bounding box on the two coordinates before
            // it measures anything, so both belong in one index.
            $table->index(['latitude', 'longitude'], 'leadhub_postal_geo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadhub_postal_codes');
    }
};
