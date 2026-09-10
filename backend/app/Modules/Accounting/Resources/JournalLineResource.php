<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use App\Modules\Accounting\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JournalLine
 */
class JournalLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'account_code' => $this->whenLoaded('account', fn () => $this->account->code),
            'account_name' => $this->whenLoaded('account', fn () => $this->account->name),
            'debit' => (float) $this->debit,
            'credit' => (float) $this->credit,
            'memo' => $this->memo,
        ];
    }
}
