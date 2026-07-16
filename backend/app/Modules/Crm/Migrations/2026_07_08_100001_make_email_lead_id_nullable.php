<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound emails can arrive unmatched (a reply we can't tie to a lead). Per the Sprint 2 design
 * those are persisted with a null lead for later manual association rather than dropped, so
 * `emails.lead_id` must be nullable. The foreign key is preserved (non-null values still must
 * reference an existing lead). Additive; outbound rows always set lead_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable(false)->change();
        });
    }
};
