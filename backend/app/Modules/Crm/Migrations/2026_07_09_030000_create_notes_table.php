<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal notes attached to a lead (Lead Internal Notes Module). The mutable content store;
 * every write also logs an append-only `note.*` Activity via NoteObserver for the immutable
 * audit trail. Mirrors the `emails` table's structure (lead_id cascade, nullable author).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_notes', function (Blueprint $table) {
            $table->id();
            // The lead this note belongs to. Leads are soft-deleted, so notes persist with them.
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            // The author. Nullable so a note survives the deletion of its author's account.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_notes');
    }
};
