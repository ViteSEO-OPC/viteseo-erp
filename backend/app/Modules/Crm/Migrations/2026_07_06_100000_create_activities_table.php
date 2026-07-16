<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            // Polymorphic subject (Lead for this sprint) — subject_type + subject_id (indexed).
            $table->nullableMorphs('subject');
            // The actor who caused the activity.
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event');         // created | updated | deleted
            $table->string('description');
            $table->json('properties')->nullable();  // changed fields (old→new) for updates
            // Append-only / immutable: created_at only (no updated_at).
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activities');
    }
};
