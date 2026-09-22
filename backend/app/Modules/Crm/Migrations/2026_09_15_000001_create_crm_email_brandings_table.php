<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An organization's email branding: one row per organization, created the first time it is needed.
 *
 * For now it holds the organization LOGO, as a pointer to an immutable email asset. Every design's logo block
 * resolves through this pointer when an email is rendered, so replacing the logo here changes the logo in
 * every design and every template that uses one — and no design ever stores an image URL or asset id for it.
 *
 * `logo_asset_id` nulls on delete only as a backstop; assets are soft-deleted and never removed while in use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_email_brandings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->unique()->constrained('orgs')->cascadeOnDelete();
            $table->foreignId('logo_asset_id')->nullable()->constrained('crm_email_assets')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_email_brandings');
    }
};
