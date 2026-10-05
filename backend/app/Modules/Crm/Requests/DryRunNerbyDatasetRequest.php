<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Services\Discovery\NerbyDatasetImporter;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Settings → NERBY → Dataset Import: the upload checked by a dry run.
 *
 * Only the CONTAINER is validated here — a file of a sane size with a JSONL name, and the source to
 * import. Every line's content is validated by {@see NerbyDatasetImporter},
 * which reports each rejected line by number instead of failing the whole upload.
 *
 * TWO SIZE CEILINGS, and the smaller one wins. {@see MAX_KILOBYTES} is the application's own; PHP's
 * `upload_max_filesize` / `post_max_size` sit in front of it and refuse a larger file before this
 * request ever sees its bytes. PHP's defaults (2M / 8M) are BELOW the ~3.5 MB Google Places dataset,
 * so a server that will run this import must raise both (e.g. 16M / 20M) — that is server
 * configuration, not this feature. When PHP refuses the file, {@see messages()} says so plainly
 * instead of Laravel's generic "failed to upload".
 *
 * Route-authorized (`role:super_admin|crm_admin` + `can('import', Lead::class)`).
 */
class DryRunNerbyDatasetRequest extends FormRequest
{
    /** Same ceiling as the spreadsheet import (KB); the current Google Places dataset is ~3.5 MB. */
    public const MAX_KILOBYTES = 10240;

    /**
     * The sources this screen offers. The importer supports every NERBY provider; the Settings flow
     * deliberately exposes Google Places only for this version (PO decision 2026-10-05), and the
     * server enforces it rather than trusting the select box.
     */
    public const SOURCES = [NerbyProvider::GOOGLE_PLACES->value];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES, 'extensions:jsonl,ndjson', $this->notEmpty(...)],
            'source' => ['required', 'string', Rule::in(self::SOURCES)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['file.uploaded' => $this->uploadFailure()];
    }

    /**
     * An empty file is refused outright. Read as a dataset it has no lines, so the importer would
     * report "nothing new to import" — true, but it reads as "everything is already in the CRM".
     */
    private function notEmpty(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value instanceof UploadedFile && $value->getSize() === 0) {
            $fail('The file is empty. Choose the dataset file again.');
        }
    }

    /** Why PHP refused the upload, in words an admin can act on. */
    private function uploadFailure(): string
    {
        $file = $this->file('file');
        $error = $file instanceof UploadedFile ? $file->getError() : null;

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return sprintf(
                'The file is larger than this server accepts (limit: %s). The server\'s upload limit must be raised before this dataset can be imported.',
                self::humanBytes((int) UploadedFile::getMaxFilesize()),
            );
        }

        return 'The file failed to upload. Try again.';
    }

    private static function humanBytes(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024).' KB';
    }
}
