<?php

namespace App\Modules\Crm\Rules;

use App\Modules\Crm\Models\Niche;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Business Niche name must be unique case-insensitively: "Retail", "retail" and "RETAIL" are one
 * niche, not three.
 *
 * Laravel's built-in `unique` rule compares with `=`, which delegates case-sensitivity to the
 * database collation — case-insensitive on the platform's MySQL (utf8mb4_unicode_ci), but
 * case-SENSITIVE on SQLite, which is what the test suite runs on. Depending on collation for a
 * business rule means the rule quietly changes with the driver, so the comparison is made explicit
 * here with the same LOWER() form used everywhere else in this feature (Niche::leadsMatching(),
 * the Leads industry filter, the backfill command).
 *
 * The unique index on `crm_niches.name` stays as a database-level backstop against exact-duplicate
 * races; this rule is what produces the friendly validation error.
 */
class UniqueNicheName implements ValidationRule
{
    /** @param int|string|null $ignoreId Primary key to exclude — the row being renamed. */
    public function __construct(private readonly int|string|null $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $exists = Niche::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($value))])
            ->when($this->ignoreId !== null, fn ($query) => $query->whereKeyNot($this->ignoreId))
            ->exists();

        if ($exists) {
            $fail('That business niche already exists.');
        }
    }
}
