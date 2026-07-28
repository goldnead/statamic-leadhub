<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadhub_tasks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('contact_id')->nullable()->constrained('leadhub_contacts')->nullOnDelete();
            $table->unsignedBigInteger('opportunity_id')->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('open')->index();
            $table->string('priority')->default('normal')->index();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->string('assignee_id')->nullable()->index();
            $table->string('created_by')->nullable();
            $table->string('completed_by')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['assignee_id', 'status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadhub_tasks');
    }
};
