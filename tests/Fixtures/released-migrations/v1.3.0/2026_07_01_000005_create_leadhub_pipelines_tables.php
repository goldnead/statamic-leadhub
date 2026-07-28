<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadhub_pipelines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('leadhub_stages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('pipeline_id')->constrained('leadhub_pipelines')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_terminal')->default(false)->index();
            $table->string('terminal_outcome')->nullable()->index();
            $table->timestamps();

            $table->unique(['pipeline_id', 'slug']);
            $table->index(['pipeline_id', 'sort_order']);
        });

        Schema::create('leadhub_opportunities', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('contact_id')->constrained('leadhub_contacts')->cascadeOnDelete();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreignId('pipeline_id')->constrained('leadhub_pipelines')->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained('leadhub_stages');
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->string('title')->nullable();
            $table->decimal('value_estimate', 12, 2)->nullable();
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->string('status')->default('open')->index();
            $table->string('outcome')->nullable()->index();
            $table->boolean('manual_override')->default(false)->index();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->string('owner_id')->nullable()->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['pipeline_id', 'stage_id']);
            $table->index(['contact_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('leadhub_stage_transitions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('opportunity_id')->constrained('leadhub_opportunities')->cascadeOnDelete();
            $table->unsignedBigInteger('from_stage_id')->nullable();
            $table->unsignedBigInteger('to_stage_id');
            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['opportunity_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadhub_stage_transitions');
        Schema::dropIfExists('leadhub_opportunities');
        Schema::dropIfExists('leadhub_stages');
        Schema::dropIfExists('leadhub_pipelines');
    }
};
