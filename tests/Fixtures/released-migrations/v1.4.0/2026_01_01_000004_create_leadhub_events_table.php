<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadhub_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('contact_id')->index();
            $table->string('type', 80)->index();
            $table->string('actor_type', 80)->nullable();
            $table->string('actor_id')->nullable();
            $table->string('summary', 500)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('contact_id')
                ->references('id')->on('leadhub_contacts')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadhub_events');
    }
};
