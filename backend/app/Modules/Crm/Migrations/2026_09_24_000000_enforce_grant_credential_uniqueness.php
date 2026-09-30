<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes "one grant-level credential per connection and type" a fact the database enforces.
 *
 * WHAT WAS WRONG. `crm_provider_credentials_unique` covers
 * (provider_connection_id, credential_type, provider_account_id), which protects per-account
 * credentials exactly as intended — but the GRANT-level row carries NULL in that last column, and
 * a unique index treats NULLs as distinct. Verified on this server's MariaDB 10.11: three
 * identical NULL-account rows were accepted while a duplicate non-NULL one was rejected. So two
 * concurrent bootstraps could each write their own grant credential, and nothing would ever
 * notice — the outbound resolver only ever reads per-account rows, so the duplicates would sit
 * there as extra decryptable copies of a superseded token, invisible and unpruned.
 *
 * WHY A GENERATED COLUMN. MariaDB has no partial indexes, so `UNIQUE … WHERE provider_account_id
 * IS NULL` cannot be expressed. Folding NULL onto a sentinel in a derived column is the smallest
 * thing that makes the invariant unforgeable, and it leaves `provider_account_id` itself — and its
 * nullOnDelete foreign key — exactly as they were. `0` is safe as the sentinel precisely because
 * `crm_provider_accounts.id` is auto-increment and can never be 0.
 *
 * VIRTUAL RATHER THAN PERSISTENT, so the same DDL runs on the SQLite database PHPUnit uses:
 * SQLite refuses to ALTER a STORED generated column onto an existing table but accepts a VIRTUAL
 * one, and both engines index it.
 *
 * THE ORDER IS LOAD-BEARING IN BOTH DIRECTIONS. `provider_connection_id` has no standalone index;
 * its foreign key currently relies on the leftmost prefix of the OLD unique. So the replacement —
 * which keeps that column leftmost — must exist before the old one is dropped, or MariaDB refuses
 * the drop. `down()` reverses it for the same reason.
 *
 * IT DOES NOT CLEAN UP. If duplicate grant rows somehow already exist, the ALTER fails loudly and
 * a person decides which credential to keep. A migration that silently deleted one would be
 * destroying a secret nobody asked it to choose between.
 */
return new class extends Migration
{
    private const TABLE = 'crm_provider_credentials';

    private const OLD_INDEX = 'crm_provider_credentials_unique';

    private const NEW_INDEX = 'crm_provider_credentials_scope_unique';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            // Mirrors provider_account_id's own type so the comparison needs no coercion.
            $table->unsignedBigInteger('account_key')
                ->virtualAs('COALESCE(provider_account_id, 0)')
                ->after('provider_account_id');
        });

        // Added BEFORE the old index is dropped — see the docblock.
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->unique(['provider_connection_id', 'credential_type', 'account_key'], self::NEW_INDEX);
        });

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropUnique(self::OLD_INDEX);
        });
    }

    public function down(): void
    {
        // Restore the original index first, so the foreign key on provider_connection_id is never
        // left without a usable index.
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->unique(['provider_connection_id', 'credential_type', 'provider_account_id'], self::OLD_INDEX);
        });

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropUnique(self::NEW_INDEX);
        });

        // Only once nothing indexes it.
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropColumn('account_key');
        });
    }
};
