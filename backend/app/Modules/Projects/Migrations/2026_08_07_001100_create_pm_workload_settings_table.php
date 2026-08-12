<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable workload capacity — the bands that turn a member's summed effort
 * score into Light / Moderate / Heavy.
 *
 * A SINGLETON row (id 1): the thresholds are a platform-wide setting a PM/Admin
 * tunes from the Workload Analysis page, so "heavy" means the same thing on
 * every board and in the task modal's assignment indicator.
 *
 * Defaults deliberately mirror the existing config('workload.thresholds') so an
 * upgrade changes nobody's workload level until someone actually adjusts them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_workload_settings', function (Blueprint $table) {
            $table->id();
            // Top of the effort range; the bands rescale with it.
            $table->unsignedInteger('max_capacity')->default(100);
            // Inclusive upper bounds: Light 0..light_max, Moderate ..moderate_max, Heavy above.
            $table->unsignedInteger('light_max')->default(20);
            $table->unsignedInteger('moderate_max')->default(40);
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_workload_settings');
    }
};
