<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member workload is now derived entirely from live effort-score aggregation
 * (active tasks/subtasks assigned to the member), so the static per-user
 * capacity_points field is no longer used.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'capacity_points')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('capacity_points');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'capacity_points')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->integer('capacity_points')->nullable()->after('role');
            });
        }
    }
};
