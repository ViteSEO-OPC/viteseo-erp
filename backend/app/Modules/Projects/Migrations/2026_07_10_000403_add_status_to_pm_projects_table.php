<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_projects', function (Blueprint $table) {
            // Lifecycle state: 'active' (default) or 'done'. A done project's
            // tasks are hidden from the active task views.
            $table->string('status', 20)->default('active')->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('pm_projects', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
