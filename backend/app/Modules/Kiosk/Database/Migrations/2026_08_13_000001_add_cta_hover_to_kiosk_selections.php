<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CTA button hover effect (none | wipe | flip | gradient | glow | shine) — a
 * global theme knob applied to every call-to-action button in the mockup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosk_selections', function (Blueprint $table): void {
            $table->string('cta_hover')->nullable()->after('radius');
        });
    }

    public function down(): void
    {
        Schema::table('kiosk_selections', function (Blueprint $table): void {
            $table->dropColumn('cta_hover');
        });
    }
};
