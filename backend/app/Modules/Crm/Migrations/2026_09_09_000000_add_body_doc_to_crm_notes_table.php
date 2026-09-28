<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rich text for internal notes, added ALONGSIDE the existing plain-text column.
 *
 * `body` keeps its job and stays NOT NULL: it is the canonical readable text, the column search,
 * exports, the spreadsheet importer and the 5000-character rule all read. `body_doc` carries the
 * Tiptap document that produced it.
 *
 * Nullable, with no backfill, on purpose — nullability IS the format discriminator:
 *
 *   body_doc IS NULL      → a legacy or imported note; render the plain text exactly as before
 *   body_doc IS NOT NULL  → render the document read-only through Tiptap
 *
 * So every note that exists today keeps rendering forever with no data rewrite, no downtime and no
 * one-way conversion. Dropping the column is a clean reverse: the notes revert to plain text, which
 * is what `body` has held all along.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_notes', function (Blueprint $table): void {
            $table->json('body_doc')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('crm_notes', function (Blueprint $table): void {
            $table->dropColumn('body_doc');
        });
    }
};
