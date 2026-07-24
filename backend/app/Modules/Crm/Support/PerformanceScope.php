<?php

namespace App\Modules\Crm\Support;

use App\Models\User;
use App\Modules\Crm\Requests\PerformanceScopeRequest;

/**
 * The resolved reporting scope for the Performance surfaces (My Performance + Reports & Performance).
 *
 * Every performance/KPI query is ownership-scoped through `Lead::scopeVisibleTo($subject)` /
 * `Activity::scopeVisibleTo($subject)`, so the ONE thing the controllers/services need is the resolved
 * `subject` user. `mode` records the intent for callers/response echoes:
 *   - `self` : the requester viewing their own portfolio (a BD's My Performance).
 *   - `all`  : an Admin/Super Admin viewing the whole CRM (their own `visibleTo` == every lead).
 *   - `bd`   : an Admin/Super Admin drilled into one Business Development Manager.
 *
 * EXTENSION SEAM (future filters — Department / Branch / Date Range / Custom Reports): add the new
 * criteria as readonly fields here, resolve them in {@see PerformanceScopeRequest},
 * and apply them wherever the subject is applied. Controllers and reused widgets/services do not change —
 * they consume a `PerformanceScope`, not a bag of request params.
 */
final class PerformanceScope
{
    public function __construct(
        public readonly User $subject,
        public readonly string $mode,
    ) {}
}
