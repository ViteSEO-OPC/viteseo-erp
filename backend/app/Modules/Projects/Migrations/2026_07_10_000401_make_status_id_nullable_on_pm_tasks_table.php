<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A task keeps a status, but when a custom status is deleted its tasks fall
     * back to "no status" (null) rather than blocking the delete. So status_id
     * becomes nullable and the FK switches restrictOnDelete → nullOnDelete.
     */
    public function up(): void
    {
        Schema::table('pm_tasks', function (Blueprint $table) {
            $table->dropForeign(['status_id']);
        });

        Schema::table('pm_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('status_id')->nullable()->change();
            $table->foreign('status_id')->references('id')->on('pm_project_statuses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pm_tasks', function (Blueprint $table) {
            $table->dropForeign(['status_id']);
        });

        Schema::table('pm_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('status_id')->nullable(false)->change();
            $table->foreign('status_id')->references('id')->on('pm_project_statuses')->restrictOnDelete();
        });
    }
};
