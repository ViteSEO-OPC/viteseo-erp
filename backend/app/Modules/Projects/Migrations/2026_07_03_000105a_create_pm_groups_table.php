<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('pm_projects')->cascadeOnDelete();
            $table->string('name');
            $table->string('color');
            $table->foreignId('leader_id')->constrained('users')->restrictOnDelete();
            $table->boolean('archived')->default(false);
            $table->timestamps();

            $table->index('project_id');
            $table->index('leader_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_groups');
    }
};
