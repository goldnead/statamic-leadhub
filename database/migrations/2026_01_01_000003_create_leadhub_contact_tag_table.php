<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadhub_contact_tag', function (Blueprint $table) {
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('tag_id');
            $table->timestamp('created_at')->nullable();
            // Eloquent's `withTimestamps()` on BelongsToMany expects both
            // created_at AND updated_at on the pivot. Adding it here keeps
            // attach() / detach() / sync() from blowing up.
            $table->timestamp('updated_at')->nullable();

            $table->primary(['contact_id', 'tag_id']);
            $table->index('contact_id');
            $table->index('tag_id');

            $table->foreign('contact_id')
                ->references('id')->on('leadhub_contacts')
                ->cascadeOnDelete();
            $table->foreign('tag_id')
                ->references('id')->on('leadhub_tags')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadhub_contact_tag');
    }
};
