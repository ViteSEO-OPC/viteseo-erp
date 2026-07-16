<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_group_members', function (Blueprint $table) {
            // Internal join table — surrogate auto-increment key; never exposed by URL.
            $table->id();
            $table->foreignId('group_id')->constrained('pm_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            // Members only; the group's leader is stored on pm_groups.leader_id.
            $table->unique(['group_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_group_members');
    }
};
