<?php

use App\Support\FeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-org entitlements. A row means the org has availed that function; the
 * FeatureRegistry defines the catalog. The default (first) org is backfilled
 * with every function so existing internal use is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->string('feature_key');
            $table->timestamps();

            $table->unique(['org_id', 'feature_key']);
        });

        // Backfill: the default org gets everything (internal company use).
        $defaultOrgId = DB::table('orgs')->orderBy('id')->value('id');

        if ($defaultOrgId) {
            $now = now();
            $rows = array_map(fn (string $key): array => [
                'org_id' => $defaultOrgId,
                'feature_key' => $key,
                'created_at' => $now,
                'updated_at' => $now,
            ], FeatureRegistry::keys());

            if ($rows) {
                DB::table('org_features')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('org_features');
    }
};
