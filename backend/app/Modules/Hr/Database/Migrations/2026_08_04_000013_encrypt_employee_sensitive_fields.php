<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypt bank accounts and government ID numbers at rest (FIN-022).
 *
 * WHAT WAS EXPOSED. `hr_employees` held `bank_account`, `bank_account_name`, `tin`, `sss_number`,
 * `philhealth_number` and `pagibig_number` in plain text, and `EmployeeResource` returned the full
 * account number to any HR viewer. A module-wide grep for `encrypted` or `Crypt::` returned nothing.
 * The Data Privacy Act treats these as sensitive personal information, and a database dump, a backup
 * copied to a laptop, or one SQL-injection foothold discloses every employee's bank details and TIN
 * in readable form.
 *
 * COLUMNS ARE WIDENED TO TEXT FIRST, and this is not cosmetic. Laravel's `encrypted` cast produces a
 * base64 JSON envelope — measured at 228 characters for a 17-character account number. That still
 * fits `varchar(255)`, which is exactly the trap: it fits today and silently truncates the first
 * time someone enters a longer account name, and a truncated ciphertext cannot be decrypted. The
 * data would be destroyed at write time with no error.
 *
 * THE BACKFILL IS IDEMPOTENT. Each value is tried as ciphertext first; only what fails to decrypt is
 * treated as plaintext and encrypted. Running this twice therefore cannot double-encrypt — which
 * matters because a half-completed migration on a large table is the realistic failure mode, and the
 * recovery for it is to run it again.
 *
 * NOT REVERSIBLE BY DESIGN. `down()` decrypts back to plaintext so the schema change can be undone,
 * but note that rolling back re-exposes the data. It exists for a failed deploy, not as an option.
 *
 * QUERYING: nothing searched or sorted on these columns — checked before writing this — so there is
 * no lost index or broken `where`. If a future feature needs to look an account number up, it needs
 * a separate blind index; encrypted columns cannot be searched.
 */
return new class extends Migration
{
    private const FIELDS = [
        'bank_account', 'bank_account_name', 'tin',
        'sss_number', 'philhealth_number', 'pagibig_number',
    ];

    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            foreach (self::FIELDS as $field) {
                $table->text($field)->nullable()->change();
            }
        });

        $this->transform(fn (string $value) => $this->looksEncrypted($value) ? $value : Crypt::encryptString($value));
    }

    public function down(): void
    {
        $this->transform(fn (string $value) => $this->looksEncrypted($value) ? Crypt::decryptString($value) : $value);

        Schema::table('hr_employees', function (Blueprint $table) {
            foreach (self::FIELDS as $field) {
                $table->string($field, 255)->nullable()->change();
            }
        });
    }

    /** Apply a transformation to every non-empty value in every sensitive column. */
    private function transform(callable $fn): void
    {
        DB::table('hr_employees')
            ->select(array_merge(['id'], self::FIELDS))
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($fn) {
                foreach ($rows as $row) {
                    $update = [];

                    foreach (self::FIELDS as $field) {
                        $value = $row->{$field};

                        if ($value === null || $value === '') {
                            continue;
                        }

                        $update[$field] = $fn((string) $value);
                    }

                    if ($update !== []) {
                        DB::table('hr_employees')->where('id', $row->id)->update($update);
                    }
                }
            });
    }

    /** Can this value be decrypted? The only honest test for "already encrypted". */
    private function looksEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
};
