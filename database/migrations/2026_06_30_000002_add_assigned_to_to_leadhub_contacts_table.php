<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leadhub_contacts', function (Blueprint $table) {
            // Statamic user id of the contact's owner (nullable = unassigned).
            $table->string('assigned_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leadhub_contacts', function (Blueprint $table) {
            $table->dropColumn('assigned_to');
        });
    }
};
