<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Team membership is now assigned per project (pm_project_members), so the
     * workspace-level membership table is no longer used.
     */
    public function up(): void
    {
        Schema::dropIfExists('pm_workspace_members');
    }

    public function down(): void
    {
        Schema::create('pm_workspace_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('pm_workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['workspace_id', 'user_id']);
            $table->index('user_id');
        });
    }
};
