<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use App\Modules\Projects\Models\Group;
use App\Support\OrgUserRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Group::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'color' => ['required', 'string', 'max:32'],
            'leader_id' => ['required', 'integer', OrgUserRule::exists()],
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['integer', OrgUserRule::exists()],
            'archived' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The leader must be one of the selected members.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $memberIds = array_map('intval', (array) $this->input('member_ids', []));
            $leaderId = $this->input('leader_id');

            if ($leaderId !== null && ! in_array((int) $leaderId, $memberIds, true)) {
                $validator->errors()->add('leader_id', 'The leader must be one of the selected members.');
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated();
        $data['archived'] = (bool) ($data['archived'] ?? false);

        return $data;
    }
}
