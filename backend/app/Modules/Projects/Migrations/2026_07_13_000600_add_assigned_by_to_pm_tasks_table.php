<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_tasks', function (Blueprint $table) {
            // Who set the task's current assignment (assignee or group).
            $table->foreignId('assigned_by_id')
                ->nullable()
                ->after('assignee_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pm_tasks', function (Blueprint $table) {
            $table->dropForeign(['assigned_by_id']);
            $table->dropColumn('assigned_by_id');
        });
    }
};
