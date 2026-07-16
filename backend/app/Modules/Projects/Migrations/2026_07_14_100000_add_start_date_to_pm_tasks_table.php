<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A task's start date. Previously the modal showed the (read-only) created_at as
 * "Start Date"; it's now a real, editable field so the due date can be validated
 * against it (deadline must be on or after the start date).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('pm_tasks', 'start_date')) {
                $table->date('start_date')->nullable()->after('deadline');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pm_tasks', function (Blueprint $table): void {
            if (Schema::hasColumn('pm_tasks', 'start_date')) {
                $table->dropColumn('start_date');
            }
        });
    }
};
