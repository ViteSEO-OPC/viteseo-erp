<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Always tied to a task; may also reference a subtask for subtask-level events.
            $table->foreignId('task_id')->constrained('pm_tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('action');
            $table->string('field_changed')->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('subtask_id')->nullable()->constrained('pm_subtasks')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('task_id');
            $table->index('user_id');
            $table->index('subtask_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_activity_logs');
    }
};
