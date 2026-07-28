<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadhub_companies', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('name_normalized')->nullable()->index();
            $table->string('website')->nullable();
            $table->string('domain')->nullable()->index();
            $table->string('industry')->nullable();
            $table->string('employee_range')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('active')->index();
            $table->string('owner_id')->nullable()->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });

        Schema::create('leadhub_contact_company', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('contact_id')->constrained('leadhub_contacts')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('leadhub_companies')->cascadeOnDelete();
            $table->string('relationship_label')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['contact_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadhub_contact_company');
        Schema::dropIfExists('leadhub_companies');
    }
};
