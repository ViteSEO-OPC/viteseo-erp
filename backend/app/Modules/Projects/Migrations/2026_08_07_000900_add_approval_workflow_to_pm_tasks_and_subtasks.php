<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member task/subtask proposals: a member may submit work for review, and it
 * stays off every board, workload and search result until a PM/Admin approves it.
 *
 * The state lives on the row itself (rather than in a separate proposals table)
 * so a reviewer edits a proposal through the ordinary task/subtask edit path and
 * approval is a single flag flip.
 *
 * The default is 'approved' so every pre-existing row — and everything a manager
 * creates directly — stays visible with no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_tasks', function (Blueprint $table) {
            $table->enum('approval_status', ['approved', 'pending', 'rejected'])
                ->default('approved')
                ->after('start_date');
            // Non-null marks a row that originated as a member proposal — it stays
            // set after approval so the author is still attributable.
            $table->foreignId('submitted_by_id')
                ->nullable()
                ->after('approval_status')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('reviewed_by_id')
                ->nullable()
                ->after('submitted_by_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_id');
            $table->text('rejection_reason')->nullable()->after('reviewed_at');

            // Drives the review queue and the "approved OR my own proposal" scope.
            $table->index(['approval_status', 'submitted_by_id']);
        });

        Schema::table('pm_subtasks', function (Blueprint $table) {
            $table->enum('approval_status', ['approved', 'pending', 'rejected'])
                ->default('approved')
                ->after('flagged_at');
            $table->foreignId('submitted_by_id')
                ->nullable()
                ->after('approval_status')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('reviewed_by_id')
                ->nullable()
                ->after('submitted_by_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_id');
            $table->text('rejection_reason')->nullable()->after('reviewed_at');

            $table->index(['approval_status', 'submitted_by_id']);
        });
    }

    public function down(): void
    {
        foreach (['pm_tasks', 'pm_subtasks'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['approval_status', 'submitted_by_id']);
                $table->dropForeign(['submitted_by_id']);
                $table->dropForeign(['reviewed_by_id']);
                $table->dropColumn([
                    'approval_status',
                    'submitted_by_id',
                    'reviewed_by_id',
                    'reviewed_at',
                    'rejection_reason',
                ]);
            });
        }
    }
};
