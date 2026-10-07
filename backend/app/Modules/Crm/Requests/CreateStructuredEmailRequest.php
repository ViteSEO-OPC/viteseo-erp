<?php

namespace App\Modules\Crm\Requests;

use App\Support\CurrentOrg;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Start a per-lead structured email from an Email Template. Only the template's id is taken: the server copies
 * its content, design and subject, so nothing the browser sends becomes the email's content here.
 */
class CreateStructuredEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // This organization's live templates only: `exists` reads the table directly, past the BelongsToOrg and
            // SoftDeletes scopes, so without the two constraints another organization's id would validate.
            'email_template_id' => [
                'required',
                'integer',
                Rule::exists('crm_email_templates', 'id')
                    ->where(fn ($query) => $query->where('org_id', CurrentOrg::id())->whereNull('deleted_at')),
            ],
        ];
    }
}
