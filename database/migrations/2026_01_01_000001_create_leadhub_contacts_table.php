<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadhub_contacts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable()->index();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('full_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('company')->nullable();
            $table->string('status', 50)->default('new')->index();
            $table->string('source')->nullable()->index();
            $table->string('source_form')->nullable()->index();
            $table->boolean('consent')->default(false);
            $table->timestamp('consent_at')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadhub_contacts');
    }
};
