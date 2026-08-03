<?php

namespace App\Modules\Crm\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts Business Rules WRITES on the `kpi` module to holders of `crm.performance.manage`, while
 * leaving `lead_scoring` writes governed solely by `crm.business_rules.manage` as before.
 *
 * WHY THIS EXISTS. Business Rules authorization is route-group level, not per module, so adding
 * `kpi` to the allow-list would have granted KPI management to every holder of
 * `crm.business_rules.manage` by inheritance — including the grant to Admin that is explicitly
 * marked temporary and development-only. Keeping the KPI catalog behind its own capability means
 * revisiting that temporary grant cannot silently change who administers KPI definitions.
 *
 * It composes rather than replaces: the group still requires `crm.business_rules.manage`, so Admin
 * and Super Admin (who hold both) pass for either module, and Lead Scoring administration is
 * untouched.
 */
class EnsurePerformanceManagement
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->route('module') === 'kpi' && ! $request->user()?->can('crm.performance.manage')) {
            abort(403, 'Managing KPI definitions requires performance management access.');
        }

        return $next($request);
    }
}
