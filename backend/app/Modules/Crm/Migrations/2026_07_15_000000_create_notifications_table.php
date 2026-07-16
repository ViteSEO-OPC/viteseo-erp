<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standard Laravel Database Notifications table — the RC2 notification foundation
 * (see KB: Inbound-Email-Integration / Notification-Engine). The first production notification is the
 * Inbound Reply Notification to a lead's assigned BD; future modules (Planner reminders, Deals, Tasks)
 * reuse this same table via additional Notification classes — no new engine. Additive; no existing
 * table is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');           // notifiable_type + notifiable_id (indexed)
            $table->text('data');                    // JSON payload (channel-agnostic)
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
