<?php

namespace App\Modules\Crm\Requests;

/**
 * Settings → NERBY → Dataset Import: the explicit confirmation of a write.
 *
 * The SAME file is sent again with the SHA-256 the dry run reported. The importer refuses any bytes
 * that do not hash to it, so a confirmation can only ever import the exact file that was reviewed —
 * and nothing about the upload has to be kept on the server between the two steps.
 */
class ImportNerbyDatasetRequest extends DryRunNerbyDatasetRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'confirm_sha256' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }
}
