<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3I — one row per inbound turn the AI auto-reply considered.
 *
 * THE UNIQUE INDEX IS THE IDEMPOTENCY GUARANTEE. A job claims its trigger by inserting this row
 * BEFORE any model call; a duplicate job, a re-run or a re-entrant dispatch loses on
 * UNIQUE(inbound_message_id) and does nothing. So one inbound message can cost at most one model
 * call and at most one send, whatever the queue does.
 *
 * IDENTIFIERS AND CODES ONLY. No body, no draft, no provider id, no customer identifier: the text
 * that was sent lives on the outbound `crm_messages` row this points at, under that table's
 * existing access rules, and nowhere else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_auto_replies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();

            $table->foreignId('conversation_id')->constrained('crm_conversations')->cascadeOnDelete();

            // The inbound turn that triggered the decision. UNIQUE: see the class docblock.
            $table->foreignId('inbound_message_id')->unique()->constrained('crm_messages')->cascadeOnDelete();

            // decided | drafting | sending | sent | failed | ambiguous | skipped
            $table->string('status', 16);

            $table->string('skip_reason', 40)->nullable();
            $table->string('failure_code', 40)->nullable();

            // The outbound row the send created, when one was created. Kept if the reply row goes.
            $table->foreignId('outbound_message_id')->nullable()->constrained('crm_messages')->nullOnDelete();

            $table->string('model', 100)->nullable();
            $table->unsignedSmallInteger('prompt_version')->nullable();

            $table->dateTime('completed_at')->nullable();

            $table->timestamps();

            // Per-conversation guards: in-flight, cooldown, hourly cap.
            $table->index(['org_id', 'conversation_id', 'created_at'], 'crm_auto_replies_org_conversation_created_index');

            // The cross-org hourly ceiling.
            $table->index('created_at', 'crm_auto_replies_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_auto_replies');
    }
};
