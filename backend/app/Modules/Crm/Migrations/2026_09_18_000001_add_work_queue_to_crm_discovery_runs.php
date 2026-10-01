<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A nationwide run's resumable work queue (H-5).
 *
 * A nationwide sweep is hundreds of paced searches — far longer than one queued job may live
 * (RunDiscoveryJob::$timeout, and the worker's own --timeout). So it now runs in SLICES: each job
 * works the queue until a deadline, checkpoints what is left here, and queues its successor.
 *
 * `work_queue` holds only the cells still to search (bounding boxes, labels, depth, and the per-niche
 * progress of a cell interrupted mid-way) — geometry already derived from the national polygon, never
 * business data. NULL whenever the run is not between slices, which is every scoped run always.
 * `continuations` counts the hand-offs, for operators reading a long run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_discovery_runs', function (Blueprint $table): void {
            $table->json('work_queue')->nullable()->after('budget_used');
            $table->unsignedInteger('continuations')->default(0)->after('work_queue');
        });
    }

    public function down(): void
    {
        Schema::table('crm_discovery_runs', function (Blueprint $table): void {
            $table->dropColumn(['work_queue', 'continuations']);
        });
    }
};
