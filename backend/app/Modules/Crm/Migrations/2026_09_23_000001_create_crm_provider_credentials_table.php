<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where provider secrets live, and the only table in this module that holds one.
 *
 * FOLLOWS THE PRECEDENT ALREADY IN THIS CODEBASE rather than inventing a second convention:
 * `crm_mailbox_oauth_tokens` stores OAuth grants as TEXT columns with Laravel `encrypted` casts on
 * the model and a `$hidden` list keeping them out of any array or JSON rendering. The same shape is
 * used here, for the same reasons, and the model carries the same protections.
 *
 * THE CONSEQUENCE OF ENCRYPTION AT REST, stated because it is load-bearing and easy to forget: each
 * row gets its own IV, so two rows holding the same token produce different ciphertext and a
 * `WHERE access_token = ?` can never match. Every lookup must therefore go through the plaintext
 * foreign keys below. That is not a limitation to work around — it is the reason `credential_type`
 * and the two FKs exist as separate, unencrypted columns.
 *
 * WHY BOTH A CONNECTION AND AN OPTIONAL ACCOUNT. Meta issues two kinds of token that matter: a
 * user/business token that belongs to the grant as a whole, and a Page Access Token that belongs to
 * one Page. The first has no resource, the second does — so `provider_account_id` is nullable and
 * its presence is what distinguishes them. Sending uses the per-resource kind; the connection-level
 * kind exists to mint those, which is Phase 3F.3's problem.
 *
 * THE UNIQUE KEY AND ITS HOLE. (connection, type, account) is unique, but MariaDB treats NULLs as
 * distinct, so nothing at the database level stops two connection-level credentials of one type.
 * The application enforces that instead, and this comment exists so the next reader does not
 * discover the gap by writing a duplicate.
 *
 * THE TABLE NAME IS SHORT ON PURPOSE. MariaDB caps identifiers at 64 characters, and Laravel
 * derives a foreign key name as <table>_<column>_foreign — so the more descriptive
 * `crm_provider_connection_credentials` produced a 66-character constraint name and the migration
 * failed at the ALTER, after the CREATE had already succeeded. DDL is not transactional here, so
 * that leaves a half-built table behind; a shorter name avoids the whole class of problem rather
 * than papering over it with hand-written constraint names.
 *
 * NO ROW IS WRITTEN BY PHASE 3F.2. The table is created and modelled; the connect flow that would
 * fill it does not exist yet, and no real token is stored anywhere in this phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_provider_credentials', function (Blueprint $table) {
            $table->id();

            // Denormalized from the connection so an org-scoped read never needs the join. Kept
            // consistent by the application; the connection remains the authority.
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();

            $table->foreignId('provider_connection_id')
                ->constrained('crm_provider_connections')
                ->cascadeOnDelete();

            // Null for a connection-level grant; set for a per-resource token such as a Page token.
            // Null-on-delete rather than cascade: losing a resource mapping must not silently
            // destroy the record that a credential existed for it.
            $table->foreignId('provider_account_id')
                ->nullable()
                ->constrained('crm_provider_accounts')
                ->nullOnDelete();

            // `page_access_token`, `user_access_token`. A small closed vocabulary, not free text.
            $table->string('credential_type', 32);

            // Ciphertext at rest via the model's `encrypted` cast. TEXT because ciphertext is
            // substantially longer than the token it wraps, and because these are never indexed.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();

            $table->dateTime('expires_at')->nullable();

            // Granted scopes, as the provider reported them. Not a secret; used to tell an operator
            // why a send failed with a permissions error.
            $table->json('scopes')->nullable();

            $table->timestamps();

            $table->unique(
                ['provider_connection_id', 'credential_type', 'provider_account_id'],
                'crm_provider_credentials_unique',
            );
            $table->index(['org_id', 'expires_at'], 'crm_provider_credentials_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_provider_credentials');
    }
};
