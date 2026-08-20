<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One statutory contribution's rates, in force from `effective_from` until a later version
 * supersedes it. There is no end date — the next version's start IS this one's end,
 * which makes gaps and overlaps impossible to express.
 */
class ContributionVersion extends Model
{
    protected $table = 'hr_contribution_versions';

    public const TYPES = ['sss', 'philhealth', 'pagibig'];

    public const BASES = ['percentage', 'bracket'];

    /**
     * WHICH salary figure the rate is applied to — not to be confused with `BASES`
     * above, which is how the charge is worked out once a figure is chosen.
     */
    public const SALARY_BASES = ['basic', 'earnings'];

    protected $fillable = [
        'type', 'effective_from', 'basis', 'salary_basis',
        'employee_rate', 'employer_rate',
        'salary_floor', 'salary_cap',
        'employee_amount_cap', 'employer_amount_cap',
        'fixed_employer_add_on', 'add_on_min_salary',
        'note', 'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'employee_rate' => 'decimal:5',
        'employer_rate' => 'decimal:5',
        'salary_floor' => 'decimal:2',
        'salary_cap' => 'decimal:2',
        'employee_amount_cap' => 'decimal:2',
        'employer_amount_cap' => 'decimal:2',
        'fixed_employer_add_on' => 'decimal:2',
        'add_on_min_salary' => 'decimal:2',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** MSC bands, lowest first — only meaningful when `basis` is `bracket`. */
    public function brackets(): HasMany
    {
        return $this->hasMany(ContributionBracket::class, 'contribution_version_id')
            ->orderBy('min_salary');
    }

    /**
     * The band a salary falls in: the highest whose `min_salary` it reaches. Returns null when
     * this version isn't bracket-based or has no bands, so the caller falls back to
     * percentage-of-salary rather than silently charging zero.
     *
     * @param  array<int, array{min_salary: float, msc: float, employer_add_on?: ?float}>  $brackets  lowest first
     * @return array{min_salary: float, msc: float, employer_add_on?: ?float}|null
     */
    public static function bandFor(array $brackets, float $salary): ?array
    {
        $band = null;
        foreach ($brackets as $candidate) {
            if ($salary >= (float) $candidate['min_salary']) {
                $band = $candidate;
                continue;
            }
            break; // sorted ascending — everything after this is out of reach
        }

        return $band;
    }

    /**
     * The Monthly Salary Credit a salary is charged on.
     *
     * @param  array<int, array{min_salary: float, msc: float}>  $brackets  lowest first
     */
    public static function mscFor(array $brackets, float $salary): ?float
    {
        $band = self::bandFor($brackets, $salary);

        return $band === null ? null : (float) $band['msc'];
    }

    /**
     * The shape PayrollService::contribution() consumes. Keeping the original config key names
     * means the pay math is untouched by this slice — only where the numbers COME FROM changed.
     *
     * @return array<string, mixed>
     */
    public function toConfig(): array
    {
        return array_filter([
            'rate' => (float) $this->employee_rate,
            'employer_rate' => (float) $this->employer_rate,
            'salary_floor' => $this->salary_floor !== null ? (float) $this->salary_floor : null,
            'salary_cap' => $this->salary_cap !== null ? (float) $this->salary_cap : null,
            'cap' => $this->employee_amount_cap !== null ? (float) $this->employee_amount_cap : null,
            'employer_cap' => $this->employer_amount_cap !== null ? (float) $this->employer_amount_cap : null,
            'fixed_employer_add_on' => (float) $this->fixed_employer_add_on,
            'add_on_min_salary' => $this->add_on_min_salary !== null ? (float) $this->add_on_min_salary : null,
            'basis' => $this->basis,
            // Which salary figure goes in. Defaulted rather than left absent so a
            // version written before this column existed reads as `basic`, which is what it was.
            'salary_basis' => $this->salary_basis ?: 'basic',
            // The MSC bands, when this version prices by bracket.
            'brackets' => $this->basis === 'bracket'
                ? $this->brackets->map(fn ($b) => [
                    'min_salary' => (float) $b->min_salary,
                    'msc' => (float) $b->msc,
                    // The EC printed for this band; null falls back to the rule above.
                    'employer_add_on' => $b->employer_add_on !== null ? (float) $b->employer_add_on : null,
                ])->values()->all()
                : null,
        ], fn ($v) => $v !== null);
    }
}
