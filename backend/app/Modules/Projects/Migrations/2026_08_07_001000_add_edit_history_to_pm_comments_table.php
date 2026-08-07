<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable comments with a visible original.
 *
 * An author may revise their own comment, but the thread stays honest: the
 * first version is snapshotted once and everyone can read it back ("View
 * Original"). A comment is never rewritten silently.
 *
 * `original_content` is nullable and set ONLY on the first edit — it is the
 * *original*, not the previous version, so later edits leave it alone. Null
 * therefore means "never edited", which is also what `edited_at` reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_comments', function (Blueprint $table) {
            $table->text('original_content')->nullable()->after('content');
            $table->timestamp('edited_at')->nullable()->after('original_content');
        });
    }

    public function down(): void
    {
        Schema::table('pm_comments', function (Blueprint $table) {
            $table->dropColumn(['original_content', 'edited_at']);
        });
    }
};
