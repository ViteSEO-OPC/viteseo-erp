<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // ERD integrity constraint #2 (belongs to exactly one parent: task XOR subtask) is enforced
            // in the app layer (LinkService createForTask/createForSubtask): MariaDB rejects CHECK
            // constraints that reference foreign-key columns (error 1901).
            $table->foreignId('task_id')->nullable()->constrained('pm_tasks')->cascadeOnDelete();
            $table->foreignId('subtask_id')->nullable()->constrained('pm_subtasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete(); // who attached the link
            $table->string('url');
            $table->timestamp('created_at')->useCurrent();

            $table->index('task_id');
            $table->index('subtask_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_links');
    }
};
