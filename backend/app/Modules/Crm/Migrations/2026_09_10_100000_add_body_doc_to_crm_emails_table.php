<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formatted email, added ALONGSIDE the existing plain-text column — the same shape as
 * `crm_notes.body_doc` (2026_09_09_000000).
 *
 * `body` keeps its job and stays NOT NULL: it is the canonical readable text, the text/plain part of
 * every message, and what the reply notifications, the Follow-ups composer, drafts, templates and the
 * automation route all read and write. `body_doc` carries the Tiptap document a formatted reply was
 * composed as; the server renders the text/html part from it at send time.
 *
 * Nullable, with no backfill, on purpose — nullability IS the format discriminator:
 *
 *   body_doc IS NULL      → every existing email, every inbound email, every draft, every template
 *                           and automation send: plain text, exactly as before
 *   body_doc IS NOT NULL  → a formatted reply; sent as text/plain + text/html
 *
 * Stored on the row rather than rendered once at request time so a SCHEDULED email keeps its
 * formatting: the scheduler re-reads the row when it is due, and the HTML is rendered then, from the
 * same validated document. Dropping the column is a clean reverse — the emails remain plain text,
 * which is what `body` has held all along.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_emails', function (Blueprint $table): void {
            $table->json('body_doc')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('crm_emails', function (Blueprint $table): void {
            $table->dropColumn('body_doc');
        });
    }
};
