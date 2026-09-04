<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Heading treatment (standard | handwritten) — a hand-drawn, signature-style
 * heading option for the human touch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosk_selections', function (Blueprint $table): void {
            $table->string('heading_style')->nullable()->after('design_style');
        });
    }

    public function down(): void
    {
        Schema::table('kiosk_selections', function (Blueprint $table): void {
            $table->dropColumn('heading_style');
        });
    }
};
