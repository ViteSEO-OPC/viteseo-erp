<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use App\Modules\Accounting\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Account
 */
class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type->value,
            'parent_id' => $this->parent_id,
            'is_active' => (bool) $this->is_active,
            // Whether this is a real bank/cash account, and so an answer to
            // "which account does this statement belong to?" on Bank Reconciliation.
            'is_bank' => (bool) $this->is_bank,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
