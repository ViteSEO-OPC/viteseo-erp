<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Designed email templates, added ALONGSIDE the existing plain-text columns.
 *
 *   content          the template's own blocks, preview text, sign-off and style overrides (EmailDesignSchema)
 *   email_design_id  the reusable design (crm_email_designs) that gives it its look
 *
 * Both nullable, with no backfill — nullability IS the format discriminator:
 *
 *   content IS NULL      → every existing template: plain text, exactly as before, with no design
 *   content IS NOT NULL  → a designed template; `body` holds the plain-text form of its design and content,
 *                          derived on the server with its {{tokens}} intact, so the Outreach composer, the
 *                          automation route and every other reader of `body` keep working unchanged
 *
 * `body` stays NOT NULL and keeps its job. `email_design_id` nulls on delete only as a backstop: designs are
 * soft-deleted, and a design a template still uses cannot be deleted at all. Rolling back leaves every
 * template with the plain-text `body` it has always had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_email_templates', function (Blueprint $table): void {
            $table->json('content')->nullable()->after('body');
            $table->foreignId('email_design_id')->nullable()->after('content')->constrained('crm_email_designs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_email_templates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('email_design_id');
            $table->dropColumn('content');
        });
    }
};
