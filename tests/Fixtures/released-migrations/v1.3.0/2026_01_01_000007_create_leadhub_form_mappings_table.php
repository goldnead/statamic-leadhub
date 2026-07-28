<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadhub_form_mappings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('form_handle')->unique();
            $table->boolean('enabled')->default(false);
            $table->string('email_field')->nullable();
            $table->string('first_name_field')->nullable();
            $table->string('last_name_field')->nullable();
            $table->string('full_name_field')->nullable();
            $table->string('phone_field')->nullable();
            $table->string('company_field')->nullable();
            $table->string('message_field')->nullable();
            $table->string('consent_field')->nullable();
            $table->string('tags_field')->nullable();
            $table->string('default_status', 50)->default('new');
            $table->string('default_source')->nullable();
            $table->json('default_tags')->nullable();
            $table->boolean('attach_full_submission')->default(true);
            $table->timestamp('last_processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadhub_form_mappings');
    }
};
