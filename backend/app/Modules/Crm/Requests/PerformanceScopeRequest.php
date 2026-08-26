<?php

namespace App\Modules\Crm\Requests;

use App\Models\User;
use App\Modules\Crm\Services\CrmAccessService;
use App\Modules\Crm\Support\PerformanceScope;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Resolves the reporting scope for the shared Performance endpoints (performance-summary, kpi-metrics,
 * activities). The DEFAULT scope is always the requester's own (`Lead::scopeVisibleTo` gives a BD their
 * portfolio and an Admin/SA the whole CRM). An ELEVATED scope — viewing a specific Business Development
 * Manager via `?user_id` — is management-only: it requires `crm.reports.view` (held by Admin/Super Admin,
 * never a BD), so a BD passing the param is rejected 403. The BD's own My Performance never sends it.
 *
 * This is the single place request params become a {@see PerformanceScope}. Future reporting filters
 * (Department / Branch / Date Range / Custom Reports) are added HERE — extra rules + extra scope fields —
 * without touching the controllers, the reused KPI services, or any widget.
 */
class PerformanceScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only an elevated (non-self) scope needs the management gate; the plain self scope is open to
        // any authenticated CRM user (route middleware already applies dashboard/auth).
        return ! $this->isElevated() || (bool) $this->user()?->can('crm.reports.view');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Drill into ONE Business Development Manager. Absent → the requester's own scope
            // (which is "All Reports" for an Admin/SA). Must reference an existing user; the extra
            // "active BD" constraint is enforced in withValidator (needs a role/status lookup).
            'user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            // FUTURE reporting filters slot in here (department_id, branch_id, from/to, report_id, …)
            // — see PerformanceScope's extension-seam note. No controller/service change required.
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $userId = $this->input('user_id');

            if ($userId !== null && $userId !== '' && ! $this->isActiveBd((int) $userId)) {
                $validator->errors()->add(
                    'user_id',
                    'The selected report subject must be an active Business Development Manager.',
                );
            }
        });
    }

    /** Whether the request asks for anything beyond the requester's own scope (management-only). */
    public function isElevated(): bool
    {
        // FUTURE: || $this->filled('department_id') || $this->filled('branch_id') || …
        return $this->filled('user_id');
    }

    /** The resolved, authorized scope the controllers/services consume. */
    public function scope(): PerformanceScope
    {
        $userId = $this->input('user_id');

        if ($userId !== null && $userId !== '') {
            return new PerformanceScope(User::findOrFail((int) $userId), 'bd');
        }

        // No elevated params → the requester's own portfolio. For Admin/SA that resolves to the whole
        // CRM (their `visibleTo` is a no-op), which is the "All Reports" aggregate.
        $requester = $this->user();
        $mode = $requester->hasAnyRole(['crm_admin', 'super_admin']) ? 'all' : 'self';

        return new PerformanceScope($requester, $mode);
    }

    /** A target is valid only when it is an active `crm_bd` — the only subject a report may scope to. */
    private function isActiveBd(int $userId): bool
    {
        // "Active" means Zerem access is not suspended (`crm_user_access`), not the shared
        // `users.status` column — a BD who has left keeps their account for TeamOra and Sprintly.
        return app(CrmAccessService::class)->excludeSuspended(User::query())
            ->whereKey($userId)
            ->whereHas('roles', fn (Builder $query) => $query->where('name', 'crm_bd'))
            ->exists();
    }
}
