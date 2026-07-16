<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_emails', function (Blueprint $table) {
            $table->id();
            // The lead this outreach email was sent to.
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            // The BD user who sent it (nullable so history survives user deletion).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('to_email');
            $table->string('to_name')->nullable();
            $table->string('subject');
            $table->text('body');
            $table->string('provider');                    // smtp | gmail | graph | resend | sendgrid | ses
            $table->string('provider_message_id')->nullable();
            $table->string('status')->default('sent');     // sent | failed
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_emails');
    }
};
