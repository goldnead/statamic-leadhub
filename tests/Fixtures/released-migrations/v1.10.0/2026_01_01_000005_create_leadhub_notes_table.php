<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadhub_notes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('contact_id')->index();
            $table->string('user_id')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->foreign('contact_id')
                ->references('id')->on('leadhub_contacts')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadhub_notes');
    }
};
