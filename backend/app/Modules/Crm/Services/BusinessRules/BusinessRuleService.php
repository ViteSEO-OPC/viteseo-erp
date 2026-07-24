<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\BusinessRules;

use App\Models\User;
use App\Modules\Crm\Enums\BusinessRuleAuditAction;
use App\Modules\Crm\Enums\BusinessRuleModule;
use App\Modules\Crm\Enums\BusinessRuleState;
use App\Modules\Crm\Models\BusinessRule;
use App\Modules\Crm\Models\BusinessRuleAudit;
use App\Modules\Crm\Models\BusinessRuleVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single WRITE boundary for Business Rules configuration (Implementation Design §5). Controllers never
 * touch the version/rule/audit models directly — every mutation is born and transitioned here, mirroring how
 * {@see \App\Modules\Crm\Services\Calling\CallSessionService} is the sole author of Call state.
 *
 * This class owns the DRAFT lifecycle: it creates the single working draft (a copy of the active version on
 * first edit), edits its rules, and discards it — each mutation inside ONE transaction with the module's
 * version rows pessimistically locked (§5.3/§5.4), and each writing exactly one immutable audit row inside
 * that same transaction (§5.5). It computes NO scores: it configures rules; the frozen engine computes
 * results. The {@see ScoringConfigProvider} is the read side and is never invoked from here (§5.10).
 *
 * Publish / rollback (and their cache invalidation + recompute dispatch) are a separate concern layered on in
 * the Publish/Rollback integration step — they are intentionally not part of this draft-lifecycle class yet.
 *
 * Invariants guaranteed here (§5.11):
 *   - At most ONE draft per module (the lock serialises concurrent "first edits").
 *   - `rule_key` is immutable — never changed by any edit.
 *   - Every mutation is audited atomically; a failed audit rolls the change back.
 *   - Version numbers are monotonic per module and never reused (§1.5).
 */
final class BusinessRuleService
{
    // ── Reads (no transaction, no audit) ──────────────────────────────────────────────────────

    /** The active version and its rules — the admin read view / the source the provider validates. */
    public function getActiveConfiguration(BusinessRuleModule $module): ?BusinessRuleVersion
    {
        return $this->versionQuery($module)
            ->where('state', BusinessRuleState::ACTIVE->value)
            ->with('rules')
            ->first();
    }

    /** The current working draft, if one exists. */
    public function getDraft(BusinessRuleModule $module): ?BusinessRuleVersion
    {
        return $this->versionQuery($module)
            ->where('state', BusinessRuleState::DRAFT->value)
            ->with('rules')
            ->first();
    }

    /** A specific version's immutable snapshot. */
    public function getVersion(BusinessRuleModule $module, int $versionNumber): ?BusinessRuleVersion
    {
        return $this->versionQuery($module)
            ->where('version_number', $versionNumber)
            ->with('rules')
            ->first();
    }

    /**
     * The full version timeline (newest first) — immutable, forward-only history.
     *
     * @return Collection<int, BusinessRuleVersion>
     */
    public function getVersionHistory(BusinessRuleModule $module): Collection
    {
        return $this->versionQuery($module)
            ->orderByDesc('version_number')
            ->get();
    }

    /**
     * The append-only audit history (newest first), optionally filtered by action and/or rule_key.
     *
     * @param  array{action?: string, rule_key?: string, version_number?: int}  $filters
     * @return Collection<int, BusinessRuleAudit>
     */
    public function getAudit(BusinessRuleModule $module, array $filters = []): Collection
    {
        $query = BusinessRuleAudit::query()->where('module', $module->value);

        if (isset($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (isset($filters['rule_key'])) {
            $query->where('rule_key', $filters['rule_key']);
        }
        if (isset($filters['version_number'])) {
            $query->where('version_number', $filters['version_number']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    // ── Draft lifecycle (transactional, locked, audited) ──────────────────────────────────────

    /**
     * Return the single draft, creating it as a copy of the active version on first edit (§5.2). Creating a
     * draft is not itself an audited action (no such action in the enum §1.0) — the audit trail begins with
     * the first real edit on the draft.
     */
    public function ensureDraft(BusinessRuleModule $module, User $actor): BusinessRuleVersion
    {
        return DB::transaction(function () use ($module, $actor): BusinessRuleVersion {
            $this->lockModule($module);

            return $this->ensureDraftLocked($module, $actor);
        });
    }

    /**
     * Edit a rule's description and/or point value ON THE DRAFT (never the active version). Only fields that
     * actually change are applied and audited; a no-op change writes no audit row.
     *
     * @param  array{description?: string, point_value?: int}  $changes
     */
    public function updateRule(BusinessRuleModule $module, string $ruleKey, array $changes, User $actor): BusinessRule
    {
        return DB::transaction(function () use ($module, $ruleKey, $changes, $actor): BusinessRule {
            $this->lockModule($module);
            $draft = $this->ensureDraftLocked($module, $actor);
            $rule = $this->requireRule($draft, $ruleKey);

            $before = [];
            $after = [];

            if (array_key_exists('description', $changes)) {
                $description = trim((string) $changes['description']);
                if ($description === '') {
                    throw ValidationException::withMessages(['description' => 'Description cannot be empty.']);
                }
                if ($description !== $rule->description) {
                    $before['description'] = $rule->description;
                    $after['description'] = $description;
                    $rule->description = $description;
                }
            }

            if (array_key_exists('point_value', $changes)) {
                $pointValue = $this->validatePointValue($changes['point_value']);
                if ($pointValue !== $rule->point_value) {
                    $before['point_value'] = $rule->point_value;
                    $after['point_value'] = $pointValue;
                    $rule->point_value = $pointValue;
                }
            }

            if ($after === []) {
                return $rule; // nothing changed → no write, no audit.
            }

            $rule->save();

            $this->audit($module, BusinessRuleAuditAction::EDIT, $draft, $actor, [
                'rule_key' => $ruleKey,
                'previous_value' => json_encode($before),
                'new_value' => json_encode($after),
            ]);

            return $rule;
        });
    }

    /** Enable a rule on the draft (idempotent — no audit if already enabled). */
    public function enableRule(BusinessRuleModule $module, string $ruleKey, User $actor): BusinessRule
    {
        return $this->setEnabled($module, $ruleKey, true, $actor);
    }

    /** Disable a rule on the draft — a disabled rule contributes 0 (idempotent — no audit if already off). */
    public function disableRule(BusinessRuleModule $module, string $ruleKey, User $actor): BusinessRule
    {
        return $this->setEnabled($module, $ruleKey, false, $actor);
    }

    /**
     * Set `display_order` on the draft from an ordered list of rule keys (index = order). Presentation only —
     * never affects any computed score (§1.2). The list must be a permutation of the draft's rule keys.
     *
     * @param  list<string>  $orderedRuleKeys
     */
    public function reorderRules(BusinessRuleModule $module, array $orderedRuleKeys, User $actor): BusinessRuleVersion
    {
        return DB::transaction(function () use ($module, $orderedRuleKeys, $actor): BusinessRuleVersion {
            $this->lockModule($module);
            $draft = $this->ensureDraftLocked($module, $actor);

            $currentKeys = $draft->rules->pluck('rule_key')->all();
            sort($currentKeys);
            $providedKeys = $orderedRuleKeys;
            sort($providedKeys);
            if ($currentKeys !== $providedKeys) {
                throw ValidationException::withMessages([
                    'order' => 'The provided order must contain exactly the draft\'s rule keys, with no additions or omissions.',
                ]);
            }

            $before = $draft->rules->mapWithKeys(fn (BusinessRule $r) => [$r->rule_key => $r->display_order])->all();

            $order = 0;
            foreach ($orderedRuleKeys as $ruleKey) {
                $rule = $this->requireRule($draft, $ruleKey);
                $rule->display_order = ++$order;
                $rule->save();
            }

            $draft->load('rules');
            $after = $draft->rules->mapWithKeys(fn (BusinessRule $r) => [$r->rule_key => $r->display_order])->all();

            if ($before === $after) {
                return $draft; // order unchanged → no audit.
            }

            $this->audit($module, BusinessRuleAuditAction::REORDER, $draft, $actor, [
                'previous_value' => json_encode($before),
                'new_value' => json_encode($after),
            ]);

            return $draft;
        });
    }

    /** Remove the working draft and its rules (cascade); audited. No-op if no draft exists. */
    public function discardDraft(BusinessRuleModule $module, User $actor): void
    {
        DB::transaction(function () use ($module, $actor): void {
            $this->lockModule($module);
            $draft = $this->findDraft($module);
            if ($draft === null) {
                return;
            }

            $this->audit($module, BusinessRuleAuditAction::DISCARD_DRAFT, $draft, $actor, [
                'previous_value' => 'draft v'.$draft->version_number,
                'new_value' => null,
            ]);

            $draft->delete(); // rules cascade via the foreign key.
        });
    }

    // ── Internals ─────────────────────────────────────────────────────────────────────────────

    private function setEnabled(BusinessRuleModule $module, string $ruleKey, bool $enabled, User $actor): BusinessRule
    {
        return DB::transaction(function () use ($module, $ruleKey, $enabled, $actor): BusinessRule {
            $this->lockModule($module);
            $draft = $this->ensureDraftLocked($module, $actor);
            $rule = $this->requireRule($draft, $ruleKey);

            if ($rule->is_enabled === $enabled) {
                return $rule; // already in the target state → no write, no audit.
            }

            $rule->is_enabled = $enabled;
            $rule->save();

            $this->audit($module, $enabled ? BusinessRuleAuditAction::ENABLE : BusinessRuleAuditAction::DISABLE, $draft, $actor, [
                'rule_key' => $ruleKey,
                'previous_value' => $enabled ? 'disabled' : 'enabled',
                'new_value' => $enabled ? 'enabled' : 'disabled',
            ]);

            return $rule;
        });
    }

    /**
     * Return the draft, creating it as a copy of the active version if absent. MUST be called with the
     * module already locked (inside a transaction) — the lock is what makes "at most one draft" hold under
     * concurrent first edits.
     */
    private function ensureDraftLocked(BusinessRuleModule $module, User $actor): BusinessRuleVersion
    {
        $draft = $this->findDraft($module);
        if ($draft !== null) {
            return $draft;
        }

        $active = $this->getActiveConfiguration($module);
        if ($active === null) {
            throw ValidationException::withMessages([
                'module' => 'No active configuration exists to base a draft on.',
            ]);
        }

        $draft = new BusinessRuleVersion();
        $draft->module = $module;
        $draft->version_number = $this->nextVersionNumber($module);
        $draft->state = BusinessRuleState::DRAFT;
        $draft->computation_spec_version = $active->computation_spec_version;
        $draft->reason_for_change = null;
        $draft->created_by = $actor->getKey();
        $draft->save();

        foreach ($active->rules as $source) {
            $copy = new BusinessRule();
            $copy->version_id = $draft->getKey();
            $copy->rule_key = $source->rule_key; // immutable identity carried forward.
            $copy->description = $source->description;
            $copy->category = $source->category;
            $copy->point_value = $source->point_value;
            $copy->is_enabled = $source->is_enabled;
            $copy->display_order = $source->display_order;
            $copy->save();
        }

        return $draft->load('rules');
    }

    private function findDraft(BusinessRuleModule $module): ?BusinessRuleVersion
    {
        return $this->versionQuery($module)
            ->where('state', BusinessRuleState::DRAFT->value)
            ->with('rules')
            ->first();
    }

    private function requireRule(BusinessRuleVersion $draft, string $ruleKey): BusinessRule
    {
        $rule = $draft->rules->firstWhere('rule_key', $ruleKey);
        if ($rule === null) {
            throw ValidationException::withMessages([
                'rule_key' => "Unknown rule '{$ruleKey}' for this configuration.",
            ]);
        }

        return $rule;
    }

    /**
     * Business validation for a point value (§1.3 + §1.2 sign invariant): it must be a whole number and a
     * NON-NEGATIVE magnitude (the sign is carried by the category — a negative input would be a
     * double-negation error).
     *
     * There is deliberately NO per-rule maximum. Fit (40), Engagement (60), and Overall (100) are
     * COMPUTATION caps defined by the frozen Business Computation Specification, not editable-value ceilings
     * for an individual rule — the scoring engine applies those caps at calculation time. A rule's magnitude
     * is therefore only bounded below (≥ 0), never above, by this service.
     */
    private function validatePointValue(mixed $value): int
    {
        if (! is_int($value)) {
            throw ValidationException::withMessages(['point_value' => 'Point value must be a whole number.']);
        }
        if ($value < 0) {
            throw ValidationException::withMessages([
                'point_value' => 'Point value must be a non-negative magnitude; the sign is determined by the rule category.',
            ]);
        }

        return $value;
    }

    private function nextVersionNumber(BusinessRuleModule $module): int
    {
        return (int) $this->versionQuery($module)->max('version_number') + 1;
    }

    /** Pessimistically lock the module's version rows for the duration of the transaction (§5.4). */
    private function lockModule(BusinessRuleModule $module): void
    {
        $this->versionQuery($module)->lockForUpdate()->get();
    }

    /**
     * Write exactly one immutable audit row inside the current transaction (§5.5).
     *
     * @param  array{rule_key?: string|null, previous_value?: string|null, new_value?: string|null, reason?: string|null}  $data
     */
    private function audit(BusinessRuleModule $module, BusinessRuleAuditAction $action, BusinessRuleVersion $version, User $actor, array $data): void
    {
        BusinessRuleAudit::create([
            'module' => $module,
            'action' => $action,
            'rule_key' => $data['rule_key'] ?? null,
            'previous_value' => $data['previous_value'] ?? null,
            'new_value' => $data['new_value'] ?? null,
            'version_number' => $version->version_number,
            'computation_spec_version' => $version->computation_spec_version,
            'reason_for_change' => $data['reason'] ?? null,
            'user_id' => $actor->getKey(),
        ]);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<BusinessRuleVersion> */
    private function versionQuery(BusinessRuleModule $module)
    {
        return BusinessRuleVersion::query()->where('module', $module->value);
    }
}
