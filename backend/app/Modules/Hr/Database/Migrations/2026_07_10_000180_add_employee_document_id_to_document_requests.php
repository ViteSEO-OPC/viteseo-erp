<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A document request can target a SPECIFIC HR-uploaded document on the employee's
// record: the employee sees it listed but must request access, and can only download
// it once HR fulfils the request.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_document_requests', function (Blueprint $table) {
            $table->foreignId('employee_document_id')->nullable()->after('employee_id')
                ->constrained('hr_employee_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_document_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_document_id');
        });
    }
};
