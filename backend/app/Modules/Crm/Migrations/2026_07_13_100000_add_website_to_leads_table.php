<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The business website URL (fit-data) — the input the Website Intelligence service audits.
 *
 * Discovery (OpenStreetMap) already resolves a business's website; storing the URL lets the Website
 * Intelligence service fetch and analyze the site later, decoupled from discovery. Plain fit-data;
 * this never drives a CRM decision by itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->string('website')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropColumn('website');
        });
    }
};
