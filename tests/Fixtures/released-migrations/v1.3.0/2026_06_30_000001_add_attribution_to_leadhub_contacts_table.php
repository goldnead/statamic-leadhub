<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leadhub_contacts', function (Blueprint $table) {
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('referrer', 1024)->nullable();
            $table->string('landing_page', 1024)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leadhub_contacts', function (Blueprint $table) {
            $table->dropColumn([
                'utm_source', 'utm_medium', 'utm_campaign',
                'utm_term', 'utm_content', 'referrer', 'landing_page',
            ]);
        });
    }
};
