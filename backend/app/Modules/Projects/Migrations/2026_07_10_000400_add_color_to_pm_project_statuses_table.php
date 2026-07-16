<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_project_statuses', function (Blueprint $table) {
            // Hex color for the status chip/column (e.g. #4fdbd7). Null for the
            // seeded defaults, which the frontend renders with fixed colors.
            $table->string('color', 20)->nullable()->after('key');
        });
    }

    public function down(): void
    {
        Schema::table('pm_project_statuses', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
