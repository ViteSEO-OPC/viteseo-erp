<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\WorkSuspension;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** HR-declared non-working days (work suspensions). */
class WorkSuspensionService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Per-request memo of suspensionsBetween(), keyed by range. A payroll run asks for the same
     * cycle once per employee, plus once each in AttendanceService and ViolationService — the
     * same pressure HolidayService's memo exists to relieve. Every write below clears it.
     *
     * @var array<string, array<string, bool>>
     */
    private array $memo = [];

    private function forgetMemo(): void
    {
        $this->memo = [];
    }

    /** Every declaration, soonest-first within the future then descending. */
    public function list(): Collection
    {
        return WorkSuspension::with('declarer')->orderByDesc('date')->get();
    }

    /**
     * Suspended dates in an inclusive range, as 'Y-m-d' => is_paid.
     *
     * @return array<string, bool>
     */
    public function betweenAsMap(string $start, string $end): array
    {
        return $this->memo["{$start}|{$end}"] ??= WorkSuspension::query()
            ->whereBetween('date', [$start, $end])
            ->get(['date', 'is_paid'])
            ->mapWithKeys(fn (WorkSuspension $s) => [$s->date->toDateString() => (bool) $s->is_paid])
            ->all();
    }

    /** The declaration covering one date, or null. */
    public function forDate(string $date): ?WorkSuspension
    {
        return WorkSuspension::where('date', $date)->first();
    }

    /**
     * Declare a date non-working.
     *
     * @param  array<string, mixed>  $data
     * @return array{suspension: WorkSuspension, warning: string|null}
     */
    public function declare(array $data, User $actor): array
    {
        $date = Carbon::parse($data['date'], config('app.timezone_local'))->toDateString();

        $this->assertNotLocked($date, 'declare a suspension on');

        // A soft-deleted row still occupies the unique date index (deleted_at is not part of
        // the key), so re-declaring a date HR previously withdrew must revive that row rather
        // than insert beside it and hit a 1062.
        $existing = WorkSuspension::withTrashed()->where('date', $date)->first();

        if ($existing && $existing->trashed()) {
            $existing->restore();
        }

        if ($existing) {
            return $this->update($existing, $data, $actor);
        }

        $suspension = WorkSuspension::create([
            'date' => $date,
            'name' => $data['name'],
            'is_paid' => (bool) $data['is_paid'],
            // Absent means false — the time clock behaves exactly as it always has.
            'work_requires_request' => (bool) ($data['work_requires_request'] ?? false),
            'note' => $data['note'] ?? null,
            'declared_by' => $actor->id,
        ])->load('declarer');

        $this->forgetMemo();

        $this->audit->log(
            'created',
            'Attendance',
            sprintf(
                'Declared %s a non-working day (%s) — %s',
                $suspension->date->format('M j, Y'),
                $suspension->is_paid ? 'paid' : 'unpaid',
                $suspension->name,
            ),
            $suspension,
        );

        return ['suspension' => $suspension, 'warning' => $this->settledWarning($date)];
    }

    /**
     * Change a declaration — in practice, flipping paid to unpaid or back.
     *
     * @param  array<string, mixed>  $data
     * @return array{suspension: WorkSuspension, warning: string|null}
     */
    public function update(WorkSuspension $suspension, array $data, User $actor): array
    {
        $date = $suspension->date->toDateString();

        $this->assertNotLocked($date, 'change the suspension on');

        $before = [
            'name' => $suspension->name,
            'is_paid' => (bool) $suspension->is_paid,
            'work_requires_request' => (bool) $suspension->work_requires_request,
            'note' => $suspension->note,
        ];

        $suspension->update([
            'name' => $data['name'] ?? $suspension->name,
            'is_paid' => array_key_exists('is_paid', $data) ? (bool) $data['is_paid'] : $suspension->is_paid,
            'work_requires_request' => array_key_exists('work_requires_request', $data)
                ? (bool) $data['work_requires_request']
                : $suspension->work_requires_request,
            'note' => array_key_exists('note', $data) ? $data['note'] : $suspension->note,
            'declared_by' => $actor->id,
        ]);

        $this->forgetMemo();
        $suspension->load('declarer');

        $after = [
            'name' => $suspension->name,
            'is_paid' => (bool) $suspension->is_paid,
            'work_requires_request' => (bool) $suspension->work_requires_request,
            'note' => $suspension->note,
        ];

        // Only the paid flag is worth spelling out — it is the one that moves money, and an
        // audit line reading "updated" tells nobody which way.
        $description = 'Updated the ' . $suspension->date->format('M j, Y') . ' non-working day';
        if ($before['is_paid'] !== $after['is_paid']) {
            $description .= $after['is_paid']
                ? ' — now PAID (the day no longer costs anyone a day\'s pay)'
                : ' — now UNPAID (the day now costs one day\'s pay)';
        }

        // Spelled out for the same reason: it changes what the time clock does to everyone who
        // presses Time in today, and "updated" tells nobody which way it went.
        if ($before['work_requires_request'] !== $after['work_requires_request']) {
            $description .= $after['work_requires_request']
                ? ' — anyone coming in must now ask HR first'
                : ' — anyone coming in may now time in without asking';
        }

        $this->audit->log('updated', 'Attendance', $description, $suspension, $before, $after);

        return ['suspension' => $suspension, 'warning' => $this->settledWarning($date)];
    }

    /** Withdraw a declaration — the day goes back to being an ordinary workday. */
    public function withdraw(WorkSuspension $suspension, User $actor): void
    {
        $date = $suspension->date->toDateString();

        $this->assertNotLocked($date, 'withdraw the suspension on');

        $suspension->delete();
        $this->forgetMemo();

        $this->audit->log(
            // `removed`, not `deleted` — AuditService::ACTIONS is the closed vocabulary the
            // audit report's filter is built from, and an unlisted verb writes entries nobody
            // can filter for.
            'removed',
            'Attendance',
            'Withdrew the ' . $suspension->date->format('M j, Y')
                . ' non-working day — it is an ordinary workday again',
            $suspension,
        );
    }

    /** A LOCKED payroll run is the one hard stop. */
    private function assertNotLocked(string $date, string $action): void
    {
        $locked = $this->runCovering($date, lockedOnly: true);

        if ($locked === null) {
            return;
        }

        throw ValidationException::withMessages([
            'date' => [
                sprintf(
                    'Cannot %s %s: the %s–%s payroll run is locked and can no longer be recomputed. Unlock it first.',
                    $action,
                    Carbon::parse($date)->format('M j, Y'),
                    $locked->cycle_start->format('M j'),
                    $locked->cycle_end->format('M j, Y'),
                ),
            ],
        ]);
    }

    /**
     * The sentence the screen shows when a run has already priced this date. A warning, not an
     * error: HR is told the figures are now stale and what to do, rather than being stopped
     * from recording something that is simply true.
     */
    private function settledWarning(string $date): ?string
    {
        $run = $this->runCovering($date, lockedOnly: false);

        if ($run === null) {
            return null;
        }

        return sprintf(
            'The %s–%s payroll run has already been run for this period. Re-run it so this day is priced correctly.',
            $run->cycle_start->format('M j'),
            $run->cycle_end->format('M j, Y'),
        );
    }

    /**
     * A run whose cycle covers this date. Company-wide, unlike PayrollFreezeService's
     * per-employee equivalent — a suspension is not about one person, so "has anyone been paid
     * for this day" is the wrong question and "has this period been priced" is the right one.
     */
    private function runCovering(string $date, bool $lockedOnly): ?PayrollRun
    {
        return PayrollRun::query()
            ->whereNotIn('type', ['thirteenth_month', 'adjustment'])
            ->whereDate('cycle_start', '<=', $date)
            ->whereDate('cycle_end', '>=', $date)
            ->when($lockedOnly, fn ($q) => $q->whereNotNull('locked_at'))
            ->when(! $lockedOnly, fn ($q) => $q->whereNotNull('run_at'))
            ->orderByDesc('cycle_end')
            ->first();
    }
}
