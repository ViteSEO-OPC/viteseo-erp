<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_leads', function (Blueprint $table) {
            $table->id();
            $table->string('business_name');
            $table->string('contact_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('industry')->nullable();
            $table->string('website_status')->default('no-website');
            $table->string('priority')->default('medium');
            $table->string('email_outreach')->default('none');
            $table->string('cold_calling')->default('not-called');
            $table->string('status')->default('new');
            $table->timestamp('last_activity')->nullable();
            $table->unsignedTinyInteger('score')->default(0);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_leads');
    }
};
