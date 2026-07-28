<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeadAttachmentRequest extends FormRequest
{
    /** Bytes accepted per file. Mirrored in the `max:` rule below (which is expressed in kilobytes). */
    public const MAX_KILOBYTES = 10240; // 10 MB

    /**
     * Extensions accepted, matching the upload modal's promise of "documents, images, or PDFs".
     * Validated with `mimes:` (which checks the file's guessed extension against its CONTENT, not
     * the client-supplied name), so renaming an executable to .pdf is rejected.
     *
     * @var list<string>
     */
    public const ALLOWED_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'csv', 'txt',
        'png', 'jpg', 'jpeg', 'webp', 'gif',
        'zip',
    ];

    public function authorize(): bool
    {
        // The route authorizes: ->can('view', 'lead') + permission:crm.lead.attachments.create.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:' . self::MAX_KILOBYTES,
                'mimes:' . implode(',', self::ALLOWED_EXTENSIONS),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.max' => 'The file may not be larger than ' . (self::MAX_KILOBYTES / 1024) . ' MB.',
            'file.mimes' => 'That file type is not supported. Upload a document, image, or PDF.',
        ];
    }
}
