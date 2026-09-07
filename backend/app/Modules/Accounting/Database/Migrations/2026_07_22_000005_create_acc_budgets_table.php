<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->string('period', 7); // YYYY-MM
            $table->foreignId('account_id')->constrained('acc_accounts')->cascadeOnDelete();
            $table->decimal('amount', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['org_id', 'period', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_budgets');
    }
};
