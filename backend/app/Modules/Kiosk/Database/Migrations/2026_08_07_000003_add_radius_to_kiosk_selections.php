<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corner style (sharp | soft | pill) — the border-radius personality knob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosk_selections', function (Blueprint $table): void {
            $table->string('radius')->nullable()->after('heading_style');
        });
    }

    public function down(): void
    {
        Schema::table('kiosk_selections', function (Blueprint $table): void {
            $table->dropColumn('radius');
        });
    }
};
