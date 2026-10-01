<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-lead AI provenance — whether an AI-planned search is what found this business.
 *
 * A SECOND DIMENSION, NOT A NEW `source` VALUE. `source` answers "which factual provider returned
 * this business", and it is load-bearing well beyond display: the prospect scopes key on it being
 * NULL or not, and GooglePlacesAttestation compares it against the literal `google_places` to refuse
 * a forged identity. A lead found through an AI-phrased Google query is still a Google Places lead,
 * so widening `source` would both lose that fact and silently route those rows around the
 * anti-forgery check. The two facts are therefore stored apart and composed at presentation time.
 *
 * NULLABLE WITH NO DEFAULT AND NO BACKFILL, which is the honest shape here. Every lead discovered
 * before this column existed carries no record of how its search was planned — the fact was never
 * captured and cannot be recovered — so those rows stay NULL and keep reading exactly as they do
 * today. A default of `false` would assert something about them that nothing verified.
 *
 *   NULL  = never recorded (every pre-existing lead, and every lane that does not report it)
 *   false = recorded, and AI did not shape the search that found it
 *   true  = recorded, and it did
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->boolean('discovery_ai_assisted')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->dropColumn('discovery_ai_assisted');
        });
    }
};
