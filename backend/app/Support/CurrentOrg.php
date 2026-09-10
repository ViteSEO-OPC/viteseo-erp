<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Org;
use App\Models\User;

/**
 * Resolves the "current" tenant org for a request — the platform-wide
 * source of truth used by the BelongsToOrg global scope.
 *
 * MVP: falls back to a single default org (the first / company org). When
 * user↔org membership lands (Phase 2), `resolve()` will read the org from the
 * authenticated user / request context instead of the first org.
 *
 * `set()` allows explicitly pinning the current org — used by the future
 * request-context resolver, by the org switcher, and by tests to prove
 * cross-tenant isolation.
 */
class CurrentOrg
{
    protected static ?int $override = null;

    protected static bool $hasOverride = false;

    /** @see suspendScope() */
    protected static bool $scopeSuspended = false;

    /**
     * Resolutions already looked up, keyed by authenticated user id (0 = guest).
     *
     * id() is called by the BelongsToOrg global scope on EVERY query it builds and by
     * the `creating` stamp on EVERY new record. Inside a request that is free, because
     * SetCurrentOrg pins the org up front. Everywhere else — a queue job, a seeder, a
     * console command, a service called directly — there is no override, so each of
     * those calls was issuing its own `select id from orgs` (or a membership lookup).
     * Payroll felt it first: the marginal cost of one employee went from under 15
     * queries to 27.75, an N+1 with no N+1 in the code, and the guard test caught it.
     *
     * Only POSITIVE results are remembered. "No org yet" must stay re-resolvable, or
     * the first caller to ask before an org exists would pin null for good.
     *
     * @var array<int, int>
     */
    protected static array $resolved = [];

    /**
     * Run something with tenant scoping switched OFF.
     *
     * For MIGRATIONS, and essentially nothing else. A data migration that writes
     * through an Eloquent model is writing through whatever that model means
     * TODAY, and today it carries a global scope that filters on a column the
     * migration may not have added yet. That is not hypothetical: adding
     * BelongsToOrg to the HR models broke
     * 2026_08_20_000002_install_statutory_contributions, which installs the PH
     * statutory tables through ContributionSetting::firstOrCreate() and suddenly
     * asked for `org_id is null` on a table without an org_id -- so `migrate:fresh`
     * itself stopped working, in tests before anything else could run.
     *
     * The honest alternative is "never use models in migrations", which is the
     * right rule and is not a thing that can be retrofitted onto a migration
     * history under a deadline. This is the narrow, visible escape hatch: it names
     * itself, it is scoped to a closure, and it restores on the way out even if the
     * closure throws.
     */
    public static function suspendScope(callable $fn): mixed
    {
        $was = static::$scopeSuspended;
        static::$scopeSuspended = true;

        try {
            return $fn();
        } finally {
            static::$scopeSuspended = $was;
        }
    }

    public static function scopeSuspended(): bool
    {
        return static::$scopeSuspended;
    }

    /**
     * The current org id: explicit override → the authenticated user's org →
     * the default (first) org as a pre-auth / jobs / seeder fallback.
     *
     * An authenticated user with NO org membership resolves to null (fail
     * closed), never to the first org — otherwise an orgless account would
     * silently read/write the provider tenant. The sole exception is
     * super_admin, whose admin surfaces (e.g. Integrations) legitimately
     * operate on the provider org.
     */
    public static function id(): ?int
    {
        if (static::$hasOverride) {
            return static::$override;
        }

        $user = auth()->user();
        $key = $user instanceof User ? (int) $user->getKey() : 0;

        if (isset(static::$resolved[$key])) {
            return static::$resolved[$key];
        }

        if ($user instanceof User) {
            $orgId = $user->currentOrgId();
            if ($orgId !== null) {
                return static::$resolved[$key] = $orgId;
            }

            if (! $user->hasRole('super_admin')) {
                return null;
            }

            /*
             * A super_admin with no membership legitimately works on the provider
             * org: that is what the admin surfaces -- billing, onboarding, the org
             * list -- operate on. This is the ONE case that still falls through.
             */
            $orgId = Org::query()->orderBy('id')->value('id');

            return $orgId === null ? null : static::$resolved[$key] = $orgId;
        }

        /*
         * NO AUTHENTICATED USER: FAIL CLOSED.
         *
         * This used to return the first org, and that silence was the bug. A queued
         * job, a console command or an unauthenticated webhook has no user, so every
         * one of them ran as tenant #1 -- reading its data, writing rows stamped with
         * its id, and reporting success. Nothing anywhere said "I did not know whose
         * data this was", because a plausible answer was always available.
         *
         * Null is refusable. A scoped read returns nothing, a stamped insert fails on
         * the NOT NULL column, and the path announces itself instead of quietly
         * serving the wrong tenant. Anything that genuinely runs outside a request
         * must now SAY which org it means -- `CurrentOrg::runAs($orgId, ...)` for one,
         * `eachOrg()` to loop them, `suspendScope()` for a migration that predates
         * the column.
         */
        return null;
    }

    /** Pin the current org (request context, org switcher, or tests). */
    public static function set(?int $orgId): void
    {
        static::$override = $orgId;
        static::$hasOverride = true;
    }

    /**
     * Clear any pinned org — fall back to the default resolution.
     *
     * Also drops the memoised resolutions: `clear()` means "forget the org context",
     * and a remembered id would outlive the data it was read from (between tests,
     * most visibly, where org ids differ per test on MySQL).
     */
    public static function clear(): void
    {
        static::$override = null;
        static::$hasOverride = false;
        static::$resolved = [];
    }

    /**
     * Run a callback once per org, each pass pinned to that tenant.
     *
     * The fix for every unattended per-tenant path. `id()` resolves from the
     * AUTHENTICATED user, and a queued job, a scheduled command and an inbound
     * webhook have none -- so they fall through to the FIRST org and silently do
     * one tenant's work while every other tenant's is never done at all. That is
     * not a crash: `outreach:sync` reported success every five minutes while
     * polling exactly one mailbox owner's inbox.
     *
     * Each pass is its own `runAs`, so one tenant's failure cannot leave the
     * pin set for the next one.
     *
     * @param  callable(int): void  $callback  receives the org id
     */
    public static function eachOrg(callable $callback): void
    {
        foreach (Org::query()->orderBy('id')->pluck('id') as $orgId) {
            static::runAs((int) $orgId, static fn () => $callback((int) $orgId));
        }
    }

    /** Run a callback as a specific org, restoring the previous context after. */
    public static function runAs(?int $orgId, callable $callback): mixed
    {
        $hadOverride = static::$hasOverride;
        $previous = static::$override;

        static::set($orgId);

        try {
            return $callback();
        } finally {
            static::$hasOverride = $hadOverride;
            static::$override = $previous;
        }
    }

    /**
     * The current pin, as an opaque pair, for save/restore around a job.
     *
     * `id()` is not enough: "no override, resolve from the user" and "override set
     * to null" are different states, and collapsing them would turn an unpinned
     * request into a pinned-to-nothing one.
     *
     * @return array{bool, int|null}
     */
    public static function snapshot(): array
    {
        return [static::$hasOverride, static::$override];
    }

    /** @param  array{bool, int|null}  $snapshot */
    public static function restore(array $snapshot): void
    {
        [static::$hasOverride, static::$override] = $snapshot;
        static::$resolved = [];
    }
}
