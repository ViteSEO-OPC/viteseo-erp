<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('pm_projects')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('assignment_type', ['group', 'individual']);
            // ERD integrity constraint #1 (exactly one of group_id / assignee_id set, matching
            // assignment_type) is enforced in the app layer (StoreTaskRequest + TaskService):
            // MariaDB rejects CHECK constraints that reference foreign-key columns (error 1901).
            $table->foreignId('group_id')->nullable()->constrained('pm_groups')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('status_id')->constrained('pm_project_statuses')->restrictOnDelete();
            $table->enum('priority', ['Low', 'Medium', 'High']);
            // Effort points: required for individual tasks, optional for group tasks (enforced in app layer).
            $table->integer('effort_score')->nullable();
            $table->date('deadline')->nullable();
            $table->timestamps();

            $table->index('project_id');
            $table->index('group_id');
            $table->index('assignee_id');
            $table->index('status_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_tasks');
    }
};
