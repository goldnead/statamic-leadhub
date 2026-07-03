<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadhub_segments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('handle')->unique();
            $table->text('description')->nullable();
            $table->json('rules')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // Materialized membership. Rebuilt reactively (per-contact re-evaluation on
        // contact-mutating events) and via the scheduled sweep for time-based rules.
        Schema::create('leadhub_segment_contact', function (Blueprint $table) {
            $table->foreignId('segment_id')->constrained('leadhub_segments')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('leadhub_contacts')->cascadeOnDelete();
            $table->timestamp('entered_at')->nullable();

            $table->primary(['segment_id', 'contact_id']);
            $table->index('contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadhub_segment_contact');
        Schema::dropIfExists('leadhub_segments');
    }
};
