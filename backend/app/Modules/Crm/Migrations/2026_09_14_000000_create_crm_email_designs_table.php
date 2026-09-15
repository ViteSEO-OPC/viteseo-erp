<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable email designs: the look an organization's designed email templates share.
 *
 * A design holds a LAYOUT (EmailDesignSchema): its styles, a header, the signature area and a footer —
 * never a template's content and never HTML. Any number of templates point at one design, so a change to
 * the design reaches every template that uses it the next time those templates are rendered.
 *
 * Org-scoped like every CRM table (BelongsToOrg). Each org has exactly one default design, marked
 * `is_default`, created by EmailDesignService the first time it is needed and never overwritten after
 * that. Soft deletes; EmailDesignService refuses to delete the default design or any design a template
 * still uses. Nothing is seeded, so an org with no rows is a valid state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_email_designs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('is_default')->default(false);
            $table->json('layout');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['org_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_email_designs');
    }
};
