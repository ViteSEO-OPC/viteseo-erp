<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Rules\MustBeBusinessDeveloper;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportLeadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // 10 MB cap (10240 KB) matches the documented import payload-size ceiling.
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
            // Default true = Preview (no writes). Client sends false to commit the import.
            // Accept the string tokens a multipart FormData sends; interpreted via boolean() below.
            'dry_run' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            // Owning BD for the imported leads. Admin/SA pick it (required on commit); a BD's
            // own import auto-assigns to the importing BD (not required — overridden in the controller).
            'assigned_to' => [
                Rule::requiredIf(fn (): bool => ! $this->isDryRun() && ! $this->user()->hasRole('crm_bd')),
                'nullable', 'integer', 'exists:users,id', new MustBeBusinessDeveloper,
            ],
        ];
    }

    public function isDryRun(): bool
    {
        // Default to the safe path (preview) when the flag is absent.
        return $this->boolean('dry_run', true);
    }
}
