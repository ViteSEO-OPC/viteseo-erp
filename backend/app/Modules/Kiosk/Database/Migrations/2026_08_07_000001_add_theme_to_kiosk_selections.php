<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Independent design picks on a selection: typography pairing, type-size scale,
 * and overall style (clean / playful) — chosen separately from the colour kit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosk_selections', function (Blueprint $table): void {
            $table->string('typography')->nullable()->after('brand_kit');
            $table->string('type_scale')->nullable()->after('typography');
            $table->string('design_style')->nullable()->after('type_scale');
        });
    }

    public function down(): void
    {
        Schema::table('kiosk_selections', function (Blueprint $table): void {
            $table->dropColumn(['typography', 'type_scale', 'design_style']);
        });
    }
};
