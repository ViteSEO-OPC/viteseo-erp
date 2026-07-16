<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website Intelligence audit findings for a lead (1:1). A first-class domain entity (not a provider
 * JSON blob) so these signals can grow into lead qualification, reporting, dashboards, re-enrichment
 * scheduling, and future KPI computation.
 *
 * **Strict data provider / audit findings only.** These are observations about a website's digital
 * maturity — availability, structure, business info, digital presence. Business interpretation and
 * scoring remain the responsibility of the future KPI Engine; nothing here computes a Lead Score,
 * changes Lead Status, assigns a BD, or touches any CRM workflow field.
 *
 * Primary signals are typed columns; the `audit` JSON column carries extended / future signals
 * (extra page types, more socials, contact forms, maps embeds, and deferred SEO / mobile /
 * performance / accessibility checks) so new capabilities need no redesign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_website_enrichments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->unique()->constrained('crm_leads')->cascadeOnDelete();

            // Availability
            $table->boolean('website_reachable')->default(false);
            $table->boolean('https_enabled')->default(false);

            // Structure — core pages (extended page types live in `audit`).
            $table->boolean('about_page')->default(false);
            $table->boolean('contact_page')->default(false);
            $table->boolean('services_page')->default(false);
            $table->boolean('careers_page')->nullable();
            $table->boolean('blog_page')->nullable();

            // Business information
            $table->json('emails_found')->nullable();
            $table->json('phones_found')->nullable();

            // Digital presence — primary channels (others live in `audit`).
            $table->string('facebook_url')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('instagram_url')->nullable();
            $table->string('youtube_url')->nullable();

            // Site metadata
            $table->string('website_title')->nullable();
            $table->text('website_description')->nullable();

            // Extended / future signals (page types, more socials, forms, maps embed, SEO, …).
            $table->json('audit')->nullable();

            // Provenance & scheduling
            $table->string('provider')->nullable();
            $table->string('provider_version')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('enriched_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_website_enrichments');
    }
};
