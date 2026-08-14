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
            // `.xls` shares the SAME 10 MB ceiling as every other format — it is never the
            // lesser-capability option. A large one is routed to the queue, not rejected.
            // `required_without:job_id`: a commit that references an already-converted job carries
            // no file, because re-uploading a 10 MB workbook to convert it twice is the thing the
            // job id exists to avoid.
            'file' => ['required_without:job_id', 'file', 'mimes:csv,txt,xls,xlsx', 'max:10240'],
            // Opaque id of a completed queued conversion, owned by the caller. Ownership, readiness
            // and single-use are enforced in XlsImportJobStore, never here.
            'job_id' => ['sometimes', 'string', 'uuid'],
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
