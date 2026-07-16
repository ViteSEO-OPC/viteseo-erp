<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-mailbox OAuth token store (keyless Gmail auth — the documented fallback for orgs that
 * disable service-account key creation). Tokens are stored encrypted (model casts). This is the
 * ONLY new persistence for the auth change; the inbound pipeline is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_mailbox_oauth_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();          // the mailbox that granted consent
            $table->string('provider')->default('gmail');
            $table->text('access_token')->nullable();    // encrypted
            $table->text('refresh_token')->nullable();   // encrypted
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_mailbox_oauth_tokens');
    }
};
