<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Enums\BusinessRuleModule;
use App\Modules\Crm\Requests\ReorderBusinessRulesRequest;
use App\Modules\Crm\Requests\UpdateBusinessRuleRequest;
use App\Modules\Crm\Resources\BusinessRuleAuditResource;
use App\Modules\Crm\Resources\BusinessRuleResource;
use App\Modules\Crm\Resources\BusinessRuleVersionResource;
use App\Modules\Crm\Services\BusinessRules\BusinessRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * HTTP surface for Settings → Business Rules (Business Rules Administration). THIN by design: every read
 * and every mutation delegates to {@see BusinessRuleService} (the single write boundary) — the controller
 * only resolves the HTTP intent and shapes the response, and never touches a model or contains business
 * logic. Route middleware gates access (crm.business_rules.view for reads, .manage for writes) and the
 * `{module}` route constraint restricts to enabled modules; a blocked business rule surfaces from the
 * service as a 422 via ValidationException (the standard `{message, errors}` envelope).
 *
 * This manages CONFIGURATION only — it exposes no path to edit a computed Lead Score, a BD Performance
 * figure, an audit record, or a historical version. Publish/rollback arrive with the Publish/Rollback
 * integration step.
 */
class BusinessRuleController extends Controller
{
    public function __construct(private readonly BusinessRuleService $service) {}

    // ── Reads (crm.business_rules.view) ───────────────────────────────────────────────────────

    public function active(string $module): BusinessRuleVersionResource
    {
        $version = $this->service->getActiveConfiguration($this->module($module));
        abort_if($version === null, 404, 'No active configuration exists for this module.');

        return new BusinessRuleVersionResource($version);
    }

    public function draft(string $module): JsonResponse|BusinessRuleVersionResource
    {
        $draft = $this->service->getDraft($this->module($module));

        // No draft is a normal state (nothing has been edited yet) — 200 with null, not 404.
        if ($draft === null) {
            return response()->json(['data' => null]);
        }

        return new BusinessRuleVersionResource($draft);
    }

    public function versions(string $module): AnonymousResourceCollection
    {
        return BusinessRuleVersionResource::collection(
            $this->service->getVersionHistory($this->module($module)),
        );
    }

    public function version(string $module, int $version): BusinessRuleVersionResource
    {
        $found = $this->service->getVersion($this->module($module), $version);
        abort_if($found === null, 404, 'Version not found.');

        return new BusinessRuleVersionResource($found);
    }

    public function audit(Request $request, string $module): AnonymousResourceCollection
    {
        /** @var array{action?: string, rule_key?: string, version_number?: int} $filters */
        $filters = $request->only(['action', 'rule_key', 'version_number']);

        return BusinessRuleAuditResource::collection(
            $this->service->getAudit($this->module($module), $filters)->load('user'),
        );
    }

    // ── Writes (crm.business_rules.manage) ────────────────────────────────────────────────────

    public function ensureDraft(Request $request, string $module): JsonResponse
    {
        $draft = $this->service->ensureDraft($this->module($module), $request->user());

        return (new BusinessRuleVersionResource($draft))->response()->setStatusCode(201);
    }

    public function discardDraft(Request $request, string $module): JsonResponse
    {
        $this->service->discardDraft($this->module($module), $request->user());

        return response()->json(['data' => null]);
    }

    public function updateRule(UpdateBusinessRuleRequest $request, string $module, string $ruleKey): BusinessRuleResource
    {
        return new BusinessRuleResource(
            $this->service->updateRule($this->module($module), $ruleKey, $request->validated(), $request->user()),
        );
    }

    public function enableRule(Request $request, string $module, string $ruleKey): BusinessRuleResource
    {
        return new BusinessRuleResource(
            $this->service->enableRule($this->module($module), $ruleKey, $request->user()),
        );
    }

    public function disableRule(Request $request, string $module, string $ruleKey): BusinessRuleResource
    {
        return new BusinessRuleResource(
            $this->service->disableRule($this->module($module), $ruleKey, $request->user()),
        );
    }

    public function reorder(ReorderBusinessRulesRequest $request, string $module): BusinessRuleVersionResource
    {
        /** @var list<string> $order */
        $order = $request->validated('order');

        return new BusinessRuleVersionResource(
            $this->service->reorderRules($this->module($module), $order, $request->user()),
        );
    }

    /** Resolve the route's module string to the enum. The route constraint guarantees an enabled module. */
    private function module(string $module): BusinessRuleModule
    {
        return BusinessRuleModule::from($module);
    }
}
