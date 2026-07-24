<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\ReimbursementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReimbursementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth:sanctum on the route; ownership from token
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(ReimbursementService::CATEGORIES)],
            'amount' => ['required', 'numeric', 'min:0'],
            'expense_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:1000'],
            // Receipts optional; up to 5 files, each image/pdf up to 5 MB (§5.5e).
            // HEIC/HEIF included so photos straight from an iPhone are accepted.
            'receipts' => ['nullable', 'array', 'max:5'],
            'receipts.*' => ['file', 'mimes:jpg,jpeg,png,pdf,heic,heif', 'max:5120'],
            // Back-compat: a lone `receipt` field is still accepted.
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,heic,heif', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'receipts.max' => 'You can attach at most 5 receipts.',
            'receipts.*.mimes' => 'Each receipt must be a JPG, PNG, PDF, or HEIC file.',
            'receipts.*.max' => 'Each receipt must be 5 MB or smaller.',
            'receipts.*.file' => 'Each receipt must be a valid file.',
            // Fires when a file exceeds the server's upload limit (PHP
            // upload_max_filesize) and never fully arrives — the raw message
            // is a cryptic "failed to upload".
            'receipts.*.uploaded' => 'A receipt was too large for the server to accept. Please use a smaller file, or ask an admin to raise the upload limit.',
            'receipt.uploaded' => 'The receipt was too large for the server to accept. Please use a smaller file, or ask an admin to raise the upload limit.',
        ];
    }
}
