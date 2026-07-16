<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_project_statuses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('pm_projects')->cascadeOnDelete();
            $table->string('name');
            // All status logic keys off `key`, never `name` (names are renameable).
            $table->enum('key', ['todo', 'in_progress', 'done', 'custom']);
            // true for the 3 seeded defaults (todo/in_progress/done); blocks delete.
            $table->boolean('is_protected')->default(false);
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_project_statuses');
    }
};
