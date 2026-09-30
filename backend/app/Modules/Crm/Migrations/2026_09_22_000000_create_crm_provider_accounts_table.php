<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which CRM tenant owns an external provider resource — the only answer to "whose is this?" for a
 * public webhook.
 *
 * WHY THE UNIQUE KEY IS NOT ORG-SCOPED, which is the single most important line in this file. A
 * public webhook arrives with no authenticated principal, so the tenant can only come from a
 * server-side mapping. If two orgs could each claim Page 12345, that mapping would be ambiguous and
 * something at request time would have to pick a winner — at 3am, inside a webhook, with a
 * customer's conversation as the prize. Making (provider, resource_type, external_id) globally
 * unique moves that collision to provisioning time, where a person sees it and decides. Ambiguity
 * becomes structurally impossible rather than handled.
 *
 * NO SECRETS HERE. This table answers ownership and nothing else. Page Access Tokens and any other
 * per-account credential belong in separate, encrypted-at-rest storage (the platform already uses
 * Laravel's `encrypted` casts for HR bank and TIN fields); mixing them in would make every
 * ownership lookup a read of a secret, and would put credentials in reach of any future admin
 * surface that lists mappings.
 *
 * DEACTIVATION, NOT DELETION. `is_active` lets a revoked Page stop resolving on the next delivery
 * while its history survives for audit — deleting the row would erase the evidence of what used to
 * be connected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_provider_accounts', function (Blueprint $table) {
            $table->id();

            // The tenant this resource belongs to. A normal column, NOT a BelongsToOrg scope: this
            // is the table that DECIDES the tenant, so scoping its reads by the current tenant
            // would be circular — the resolver would need an org to find the org.
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();

            // Vendor, matching config/inbound.php's provider allowlist. `meta` today.
            $table->string('provider', 64);

            // What kind of resource the external id names: page, instagram_business_account.
            $table->string('resource_type', 32);

            // The provider's own id for it — a Page ID or an IG Business Account ID.
            $table->string('external_id', 191);

            // Human label for an admin surface. Never used for a decision.
            $table->string('label', 191)->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // THE security-critical key: one external resource, at most one CRM org, platform-wide.
            $table->unique(['provider', 'resource_type', 'external_id'], 'crm_provider_accounts_resource_unique');

            // Listing a tenant's connected resources.
            $table->index(['org_id', 'provider'], 'crm_provider_accounts_org_provider_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_provider_accounts');
    }
};
