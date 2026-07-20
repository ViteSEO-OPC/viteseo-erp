<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Concerns UI refresh: an optional free-text "person being reported" and supporting
// file attachments. Attachments are stored as JSON ({ path, name, size, mime }); files
// live on the public disk under concern-attachments/ with a random hashed filename. For
// ANONYMOUS concerns the submitter's identity is protected before storage — image EXIF is
// stripped (GD re-encode) and the display name is generalised (see ConcernService).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_concerns', function (Blueprint $table) {
            $table->string('reported_person')->nullable()->after('description');
            $table->json('attachments')->nullable()->after('reported_person');
        });
    }

    public function down(): void
    {
        Schema::table('hr_concerns', function (Blueprint $table) {
            $table->dropColumn(['reported_person', 'attachments']);
        });
    }
};
