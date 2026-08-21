<?php

use App\Modules\Crm\Services\WebsiteIdentityVerifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website identity verification — records WHERE an audit landed and whether the page it found could
 * be tied back to the business.
 *
 * Until now the audit recorded only that a URL responded, and `website_status` was derived from that
 * alone. A reachable page is not evidence of ownership (a mis-tagged node, a parked domain, or a
 * redirect to an unrelated host all respond), so {@see WebsiteIdentityVerifier}
 * now decides ownership and its verdict is persisted here — evidence, not just a boolean, so the
 * resulting `website_status` is explainable after the fact.
 *
 * All columns are nullable: rows audited before this migration were never identity-checked, and NULL
 * says exactly that ("not evaluated"), which `false` would not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_lead_website_enrichments', function (Blueprint $table) {
            // Where the request actually landed (post-redirect) and what the page calls itself.
            $table->string('final_url', 2048)->nullable()->after('youtube_url');
            $table->string('canonical_url', 2048)->nullable()->after('final_url');

            // The verdict. NULL = never evaluated (pre-existing rows), not "failed".
            $table->boolean('identity_verified')->nullable()->after('canonical_url');
            $table->unsignedTinyInteger('identity_confidence')->nullable()->after('identity_verified');
            $table->json('identity_signals')->nullable()->after('identity_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('crm_lead_website_enrichments', function (Blueprint $table) {
            $table->dropColumn([
                'final_url', 'canonical_url',
                'identity_verified', 'identity_confidence', 'identity_signals',
            ]);
        });
    }
};
