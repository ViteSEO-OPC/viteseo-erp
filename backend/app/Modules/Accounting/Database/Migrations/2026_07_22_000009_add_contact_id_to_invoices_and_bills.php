<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable + no DB-level FK constraint so it applies cleanly on SQLite too;
        // the relationship is enforced in the service layer.
        Schema::table('acc_invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('contact_id')->nullable();
        });
        Schema::table('acc_bills', function (Blueprint $table) {
            $table->unsignedBigInteger('contact_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('acc_invoices', function (Blueprint $table) {
            $table->dropColumn('contact_id');
        });
        Schema::table('acc_bills', function (Blueprint $table) {
            $table->dropColumn('contact_id');
        });
    }
};
