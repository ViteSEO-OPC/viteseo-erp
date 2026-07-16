<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// When an employee requests an "Other" document, they specify which one — captured
// here so HR knows what's actually being asked for.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_document_requests', function (Blueprint $table) {
            $table->string('other_detail')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('hr_document_requests', function (Blueprint $table) {
            $table->dropColumn('other_detail');
        });
    }
};
