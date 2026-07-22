<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Digital Presence (social) + Marketplace Presence (e-commerce) for a lead.
 *
 * Every column is nullable/optional: existing leads keep working, and an Excel import whose
 * sheet lacks these columns imports exactly as before (values stored NULL — no validation error,
 * no breaking change). Plain string columns are queryable, index-friendly, and clickable-link
 * ready; a future platform is just another nullable column — no schema redesign required.
 *
 * "Website Domain" is NOT added here — it reuses the existing `crm_leads.website` column (no
 * duplicate storage). `tiktok` (social profile) is deliberately DISTINCT from `tiktok_shop`
 * (marketplace storefront).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            // Digital Presence (social)
            $table->string('facebook')->nullable()->after('website');
            $table->string('instagram')->nullable()->after('facebook');
            $table->string('tiktok')->nullable()->after('instagram');
            $table->string('linkedin')->nullable()->after('tiktok');
            // Marketplace Presence (e-commerce)
            $table->string('shopee')->nullable()->after('linkedin');
            $table->string('lazada')->nullable()->after('shopee');
            $table->string('tiktok_shop')->nullable()->after('lazada');
            $table->string('other_marketplace')->nullable()->after('tiktok_shop');
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->dropColumn([
                'facebook', 'instagram', 'tiktok', 'linkedin',
                'shopee', 'lazada', 'tiktok_shop', 'other_marketplace',
            ]);
        });
    }
};
