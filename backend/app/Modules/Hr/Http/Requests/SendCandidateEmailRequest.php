<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\RecruitmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A candidate email as HR composed it in the modal. */
class SendCandidateEmailRequest extends FormRequest
{
    /** Per file, matching the rest of the module's uploads (PHP's own cap here is 8M). */
    private const MAX_FILE_KB = 5120;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(RecruitmentService::EMAIL_TYPES)],

            'to' => ['required', 'email:rfc', 'max:255'],
            // A hiring email copies a manager or two, not a mailing list. The cap is what
            // separates "cc the panel" from using the board as a bulk sender.
            'cc' => ['nullable', 'array', 'max:10'],
            'cc.*' => ['email:rfc', 'max:255'],
            'bcc' => ['nullable', 'array', 'max:10'],
            'bcc.*' => ['email:rfc', 'max:255'],
            'reply_to' => ['nullable', 'email:rfc', 'max:255'],

            'subject' => ['required', 'string', 'max:255'],
            // HTML from the editor. The ceiling is generous because markup inflates the
            // count; the server sanitises it before anything is stored or sent.
            'body_html' => ['required', 'string', 'max:50000'],

            // Assessment only — the board badge reads it off the candidate. Day boundaries
            // are Manila's, so "not in the past" is judged there too.
            'deadline' => ['nullable', 'date', 'after_or_equal:' . now('Asia/Manila')->toDateString()],

            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => [
                'file',
                'max:' . self::MAX_FILE_KB,
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,webp,txt,csv,zip',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.email' => 'Enter a valid recipient email address.',
            'cc.*.email' => 'One of the Cc addresses is not a valid email.',
            'bcc.*.email' => 'One of the Bcc addresses is not a valid email.',
            'reply_to.email' => 'Enter a valid reply-to email address.',
            'subject.required' => 'Give the email a subject.',
            'body_html.required' => 'Write something in the message before sending.',
            'deadline.after_or_equal' => 'The deadline cannot be in the past.',
            'attachments.max' => 'Attach up to 5 files.',
            'attachments.*.max' => 'Each attachment must be 5 MB or smaller.',
        ];
    }
}
