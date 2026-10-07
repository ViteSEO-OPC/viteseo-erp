<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Requests\Concerns\ValidatesDesignedReply;
use App\Modules\Crm\Services\Mail\EmailDocument;
use App\Support\CurrentOrg;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SendEmailRequest extends FormRequest
{
    use ValidatesDesignedReply;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            // Optional override; defaults to the lead's email in the service.
            'to' => ['nullable', 'email', 'max:255'],
            // RC3 Send-Later: when present (and future), the email is SCHEDULED instead of sent now.
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            // Optional and additive: the Tiptap document of a formatted reply (Lead Detail's composer).
            // Absent or null → a plain-text email, exactly as every caller has always sent it. Present →
            // it must be an email-safe document (checked below), and `body` is derived from it.
            //
            // JSON, never HTML: no field on this request accepts markup. The HTML part of the message
            // is written by the server, from this document, at send time.
            //
            // Its text survives the request intact because `body_doc.*` is exempt from TrimStrings in
            // bootstrap/app.php — "Hello " + bold "world" must not lose the space between them.
            'body_doc' => ['sometimes', 'nullable', 'array'],

            // WHICH DESIGNED TEMPLATE THIS WAS COMPOSED FROM, so the send path can render the design
            // the sender previewed instead of its flattened plain text. An id, never markup: no field
            // on this request accepts HTML, and the server remains the only thing that renders any.
            //
            // Optional and additive. Absent — every existing caller — sends plain text exactly as
            // before. Present but edited or undesigned is not an error either: the send path checks
            // both, and a template deleted after the row was stored, and falls back to the plain-text
            // body it already carries.
            //
            // Scoped to THIS organization's live templates, as SaveDraftRequest is: `exists` reads the
            // table directly, past the BelongsToOrg and SoftDeletes scopes, so without the two constraints
            // another organization's id (or a deleted one) would validate and be stored on the row.
            'email_template_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('crm_email_templates', 'id')
                    ->where(fn ($query) => $query->where('org_id', CurrentOrg::id())->whereNull('deleted_at')),
            ],
            // THE EMAIL DESIGN A LEAD DETAILS REPLY IS SENT IN, when the BD chose one. An id, never markup.
            // Absent or null — every existing caller, and Reply's "No design" — sends exactly as before.
            //
            // A designed reply is send-now only and is never also a template send: both combinations are
            // refused rather than quietly ignored.
            'email_design_id' => [
                'sometimes',
                'nullable',
                ...$this->emailDesignIdRules(),
                'prohibits:scheduled_at,email_template_id',
            ],
        ];
    }

    /**
     * The document checks run bytes → depth → structure, in that order, inside
     * {@see EmailDocument::problem()} — the same security-required ordering as the notes validator.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $doc = $this->emailDocument();

            if ($doc !== null) {
                $problem = EmailDocument::problem($doc);
                if ($problem !== null) {
                    $validator->errors()->add('body_doc', $problem);

                    return;
                }

                // Structurally fine but reads as nothing (only empty paragraphs): no message to send.
                if (EmailDocument::toText($doc) === '') {
                    $validator->errors()->add('body_doc', 'Write a message before sending.');

                    return;
                }
            }

            // A Lead Details reply sent in a design: its (otherwise sendable) document must also fit the
            // design. Checked only once the design id itself has validated.
            if ($this->emailDesignId() !== null && ! $validator->errors()->has('email_design_id')) {
                $this->validateDesignedReplyDocument($validator, $doc);
            }
        });
    }

    /**
     * The submitted document, or null for a plain-text send.
     *
     * Read raw rather than through `validated()`, matching ValidatesNoteDocument: it has already been
     * validated as a whole by the after-hook above.
     *
     * @return array<string, mixed>|null
     */
    public function emailDocument(): ?array
    {
        $doc = $this->input('body_doc');

        return is_array($doc) && $doc !== [] ? $doc : null;
    }

    /** The Email Design a Lead Details reply is to be sent in, or null for every other send. */
    public function emailDesignId(): ?int
    {
        $id = $this->input('email_design_id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * The text to store and send as the text/plain part.
     *
     * For a formatted reply this is the SERVER's projection of the document, not the client's `body`:
     * the text part and the HTML part are then produced from the same validated source and cannot
     * disagree — a client cannot pair innocuous text with a different formatted message. For every
     * plain-text caller it is `body`, exactly as submitted.
     */
    public function bodyText(): string
    {
        $doc = $this->emailDocument();

        return $doc !== null ? EmailDocument::toText($doc) : (string) $this->validated('body');
    }
}
