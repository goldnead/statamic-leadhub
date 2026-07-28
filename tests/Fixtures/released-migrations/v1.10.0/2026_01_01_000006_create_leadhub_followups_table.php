<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadhub_followups', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('contact_id')->index();
            $table->timestamp('due_at')->index();
            $table->text('note')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->string('created_by')->nullable();
            $table->string('completed_by')->nullable();
            $table->timestamps();

            $table->foreign('contact_id')
                ->references('id')->on('leadhub_contacts')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadhub_followups');
    }
};
