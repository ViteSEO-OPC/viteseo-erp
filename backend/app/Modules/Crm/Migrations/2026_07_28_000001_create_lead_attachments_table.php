<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files uploaded against a lead (Lead Attachments Module). Mirrors `crm_notes`: lead_id cascade,
 * nullable uploader, soft deletes — so an attachment survives its uploader's account being removed
 * and a soft-deleted lead keeps its files.
 *
 * `path` is a key on the PRIVATE disk (storage/app/private), never a public URL: lead files inherit
 * the lead's ownership boundary and are streamed through an authorized download endpoint. The stored
 * filename is randomised by Laravel; `original_name` is the display name and is never used to build
 * a filesystem path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_attachments', function (Blueprint $table) {
            $table->id();
            // The lead this file belongs to. Every read is filtered by this column — an attachment
            // is never addressable outside its lead (see the routes' scopeBindings).
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            // The uploader. Nullable so the file survives deletion of their account.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Storage key on the private disk, e.g. lead-attachments/12/9f3a....pdf
            $table->string('path');
            // Client-supplied display name, shown in the UI and used for the download filename.
            $table->string('original_name');
            $table->string('mime_type');
            // Bytes. unsignedBigInteger rather than integer so the column is not the limiting factor.
            $table->unsignedBigInteger('size');
            $table->softDeletes();
            $table->timestamps();

            // The list query is always "this lead's attachments, newest first" — a composite index
            // on exactly that access path, so the sort is served by the index rather than a filesort.
            $table->index(['lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_attachments');
    }
};
