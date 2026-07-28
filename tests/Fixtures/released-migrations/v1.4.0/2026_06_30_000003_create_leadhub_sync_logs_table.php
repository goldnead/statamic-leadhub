<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadhub_sync_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('contact_uuid')->nullable()->index();
            $table->string('contact_label')->nullable();
            $table->string('destination')->index();   // config key of the destination
            $table->string('driver');                 // hubspot / brevo / webhook / …
            $table->string('event')->nullable();      // what triggered it
            $table->string('status')->index();        // success / failed
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('message')->nullable();      // error or remote id
            $table->string('remote_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadhub_sync_logs');
    }
};
