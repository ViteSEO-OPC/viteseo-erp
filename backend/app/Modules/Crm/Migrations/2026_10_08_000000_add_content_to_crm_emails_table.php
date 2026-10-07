<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-lead structured email: the template content document (EmailDesignSchema, version 1) a BD started
 * from a designed Email Template and customised for one lead — cards, rows, actions and all.
 *
 * WHY A COPY ON THE EMAIL. A structured email is created by copying the template's content onto the lead's
 * draft, so the BD can change it for THIS lead (a price, a line, a sentence) without touching the reusable
 * template every other lead is offered. `email_template_id` then only records where it started, and
 * `email_design_id` is the design it is shown in. The send renders `email_design_id` with this content through
 * the one renderer the preview uses, and stores the result in `body_html`.
 *
 * NULLABLE, AND NULL IS THE NORMAL CASE. Every existing email — plain, formatted, designed reply, composer,
 * scheduled, automation — carries NULL and behaves exactly as before. Nothing is backfilled and nothing is
 * indexed: the column is only ever read from the row it belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->json('content')->nullable()->after('body_doc');
        });
    }

    public function down(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->dropColumn('content');
        });
    }
};
