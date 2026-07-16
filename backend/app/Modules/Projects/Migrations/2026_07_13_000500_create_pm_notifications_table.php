<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();      // recipient
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');                                                     // task.assigned | comment.created
            $table->foreignId('project_id')->nullable()->constrained('pm_projects')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('pm_tasks')->cascadeOnDelete();
            $table->foreignId('comment_id')->nullable()->constrained('pm_comments')->cascadeOnDelete();
            $table->json('data')->nullable();                                           // title / snippet
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_notifications');
    }
};
