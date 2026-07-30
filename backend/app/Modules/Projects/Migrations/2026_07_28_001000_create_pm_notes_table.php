<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rich-text notes (Tiptap). One unified table for both scopes:
     *   - scope=user    → one private note per user (project_id null)
     *   - scope=project → one private note per user PER project
     *
     * Every note belongs to exactly one author and is readable/writable by that
     * author only — there is no shared note in v1, so no visibility column.
     */
    public function up(): void
    {
        Schema::create('pm_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->enum('scope', ['user', 'project']);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('pm_projects')->cascadeOnDelete();
            // Tiptap JSON document — the source of truth. Client HTML is never
            // stored; HTML is rendered from this JSON if it's ever needed.
            $table->json('content');
            // Plain-text projection built server-side on save. Prepares full-text
            // search / previews without parsing JSON at query time.
            $table->longText('content_text')->nullable();
            // Optimistic-concurrency token: a save carries the version it was
            // built on, and is rejected (409) if the server has moved on.
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('last_saved_at')->nullable();
            $table->timestamps();

            // MySQL treats NULLs as distinct in a unique index, so this enforces
            // "one project note per user per project" but NOT "one user note per
            // user" (project_id is null there) — the service resolves the user
            // note through firstOrCreate inside a transaction instead.
            $table->unique(['scope', 'user_id', 'project_id']);
            $table->index(['project_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_notes');
    }
};
