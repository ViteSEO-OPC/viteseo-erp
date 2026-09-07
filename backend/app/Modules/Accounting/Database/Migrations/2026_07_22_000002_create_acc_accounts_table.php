<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('type'); // asset | liability | equity | income | expense
            $table->foreignId('parent_id')->nullable()->constrained('acc_accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['org_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_accounts');
    }
};
