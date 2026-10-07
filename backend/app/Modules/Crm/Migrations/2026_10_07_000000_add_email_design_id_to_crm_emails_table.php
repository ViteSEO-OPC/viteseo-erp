<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Email Design a Lead Details reply was sent in — the BD's explicit choice of an existing design to
 * frame a free-form reply.
 *
 * WHY THE ROW REMEMBERS IT. A designed reply carries BOTH a `body_doc` (the message the BD wrote) and a
 * `body_html` (the design it went out in). Formatted replies without a design also store `body_html`, so
 * the thread cannot tell the two apart from the columns it already has; this id is that signal. It also
 * keeps the choice on the record the send path reads, exactly like `email_template_id`.
 *
 * NULLABLE, AND NULL IS THE NORMAL CASE. Every existing email, every plain or formatted reply sent with
 * "No design", every composer, draft, scheduled and automation send carries NULL and behaves exactly as
 * before. Nothing is backfilled.
 *
 * `nullOnDelete` rather than cascade: removing a design must never remove the record that an email was
 * sent. Designs are soft-deleted in practice, so the id normally survives, and the stored `body_html`
 * snapshot keeps showing what the recipient received either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->foreignId('email_design_id')
                ->nullable()
                ->after('email_template_id')
                ->constrained('crm_email_designs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->dropConstrainedForeignId('email_design_id');
        });
    }
};
