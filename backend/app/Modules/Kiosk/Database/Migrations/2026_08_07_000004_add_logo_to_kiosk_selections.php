<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prospect logo — stored inline as a base64 `data:image/...` URI (not a file on
 * disk). This keeps the generated site self-contained: the same string powers the
 * live preview AND the "Export to HTML" download, so a BD can hand the prospect a
 * single .html with their logo baked in, no asset hosting required. longText so a
 * small PNG/SVG data URI fits; the controller caps the size on the way in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosk_selections', function (Blueprint $table): void {
            $table->longText('logo')->nullable()->after('radius');
        });
    }

    public function down(): void
    {
        Schema::table('kiosk_selections', function (Blueprint $table): void {
            $table->dropColumn('logo');
        });
    }
};
