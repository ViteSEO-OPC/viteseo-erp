<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'head_id' => $this->head_id,
            'head' => $this->head ? [
                'id' => $this->head->id,
                'name' => $this->head->user?->name,
            ] : null,
            'positions' => $this->positions
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])
                ->values(),
            // Current role assignments: employees in this department who hold a
            // role. The modal buckets these under each position.
            'assignments' => $this->employees
                ->filter(fn ($e) => filled($e->employee_role))
                ->map(fn ($e) => [
                    'employee_id' => $e->id,
                    'name' => $e->user?->name,
                    'role' => $e->employee_role,
                ])
                ->values(),
            'created_at' => $this->created_at,
        ];
    }
}
