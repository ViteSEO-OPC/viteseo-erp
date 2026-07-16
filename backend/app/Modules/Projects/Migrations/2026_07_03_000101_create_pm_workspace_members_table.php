<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_workspace_members', function (Blueprint $table) {
            // Internal join table — surrogate auto-increment key; never exposed by URL.
            $table->id();
            $table->foreignId('workspace_id')->constrained('pm_workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            // A user belongs to a workspace at most once. Role stays global on users (no role column here).
            $table->unique(['workspace_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_workspace_members');
    }
};
