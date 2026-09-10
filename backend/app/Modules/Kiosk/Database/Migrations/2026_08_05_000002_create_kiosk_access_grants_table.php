<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A temporary kiosk login a BD issues to a client for a long-distance demo. The
 * linked user (role kiosk_client) can only run the configurator, and only until
 * `expires_at` (24h) — enforced by the EnsureKioskAccess middleware.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiosk_access_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();       // the temp client
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label');            // client / business name for the BD's reference
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk_access_grants');
    }
};
