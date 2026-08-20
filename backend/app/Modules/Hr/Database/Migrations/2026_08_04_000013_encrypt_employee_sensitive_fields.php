<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Encrypt bank accounts and government ID numbers at rest. */
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
