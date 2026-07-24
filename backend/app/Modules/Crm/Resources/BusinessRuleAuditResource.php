<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\BusinessRuleAudit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API shape for an immutable Business Rule audit entry (read-only). Surfaces the full provenance of a
 * configuration change; `user` is included only when eager-loaded. Nothing here is editable — the audit
 * trail is append-only (§1.3).
 *
 * @mixin BusinessRuleAudit
 */
class BusinessRuleAuditResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'module' => $this->module->value,
            'action' => $this->action->value,
            'ruleKey' => $this->rule_key,
            'previousValue' => $this->previous_value,
            'newValue' => $this->new_value,
            'versionNumber' => $this->version_number,
            'computationSpecVersion' => $this->computation_spec_version,
            'reasonForChange' => $this->reason_for_change,
            'userId' => $this->user_id !== null ? (string) $this->user_id : null,
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => (string) $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
