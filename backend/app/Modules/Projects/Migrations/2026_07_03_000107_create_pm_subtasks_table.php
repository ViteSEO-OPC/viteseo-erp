<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_subtasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('task_id')->constrained('pm_tasks')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('assignee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('status_id')->constrained('pm_project_statuses')->restrictOnDelete();
            $table->enum('priority', ['Low', 'Medium', 'High']);
            $table->integer('effort_score'); // required
            $table->date('deadline')->nullable();
            // null when valid; set when the assignee is no longer a valid group member (see R4).
            $table->enum('flag_reason', ['assignee_left_group', 'group_reassigned'])->nullable();
            $table->timestamp('flagged_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('task_id');
            $table->index('assignee_id');
            $table->index('status_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_subtasks');
    }
};
