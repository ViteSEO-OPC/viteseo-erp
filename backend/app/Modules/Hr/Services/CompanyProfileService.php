<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\CompanyProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The single company-profile row (Company → Settings). Every document that prints a
 * letterhead — the payslip PDF, the payslip on screen, the payroll workbook, the report
 * PDF — reads its name and address through here instead of hardcoding them.
 */
class CompanyProfileService
{
    /**
     * The opening values, matching what those documents printed before this table existed.
     * Also the safety net: a database whose row was somehow deleted still renders a payslip
     * with the right letterhead rather than a blank one.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'legal_name' => 'VITE SEO DIGITAL ADVERTISING OPC',
        'trade_name' => 'Viteseo',
        'address' => '26L Burgundy Corporate Tower, 252 Sen. Gil Puyat Avenue, '
            .'Pio Del Pilar 1230 City of Makati NCR, Fourth District Philippines',
        'tin' => null,
        'sss_employer_number' => null,
        'philhealth_employer_number' => null,
        'pagibig_employer_number' => null,
        'email' => null,
        'phone' => null,
        'website' => null,
    ];

    /** Where the logo lives on the private disk. */
    private const LOGO_DIR = 'hr/company';

    /**
     * Per-request memo. A payroll export renders one letterhead per payslip, so this row is
     * read many times in a single request. update() keeps it in step.
     */
    private ?CompanyProfile $memo = null;

    public function __construct(private readonly AuditService $audit) {}

    public function get(): CompanyProfile
    {
        return $this->memo ??= CompanyProfile::firstOrCreate([], self::DEFAULTS);
    }

    /**
     * What a document's letterhead needs, already resolved: the name, the address, and the
     * logo as a data URI (dompdf cannot fetch a signed URL, and the disk is private anyway).
     *
     * @return array{name: string, address: string, logo: ?string}
     */
    public function letterhead(): array
    {
        $profile = $this->get();

        return [
            'name' => $profile->legal_name ?: self::DEFAULTS['legal_name'],
            'address' => $profile->address ?: '',
            'logo' => $this->logoDataUri($profile),
        ];
    }

    /**
     * The logo inlined as `data:image/png;base64,…`, or null when there is none. Never throws:
     * a missing file must degrade to a letterhead without a logo, not a failed payslip.
     */
    public function logoDataUri(?CompanyProfile $profile = null): ?string
    {
        $path = ($profile ?? $this->get())->logo_path;
        if (! $path) {
            return null;
        }

        $disk = Storage::disk(PrivateFileService::DISK);
        if (! $disk->exists($path)) {
            return null;
        }

        $bytes = $disk->get($path);
        if ($bytes === null || $bytes === '') {
            return null;
        }

        $mime = $disk->mimeType($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /** @param array<string, mixed> $data */
    public function update(array $data): CompanyProfile
    {
        $profile = $this->get();

        // Only the known fields, and only when supplied — an absent key keeps its value.
        $changes = [];
        foreach (array_keys(self::DEFAULTS) as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field] === '' ? null : $data[$field];
            }
        }

        // The legal name is what the payslip prints; emptying it would render a nameless
        // document. The request already requires it, so this only guards a partial PATCH.
        if (array_key_exists('legal_name', $changes) && ! $changes['legal_name']) {
            unset($changes['legal_name']);
        }

        $before = [];
        $after = [];
        foreach ($changes as $field => $value) {
            $old = $profile->getOriginal($field);
            if ($old != $value) {
                $before[$field] = $old;
                $after[$field] = $value;
            }
        }

        $profile->update($changes);
        $this->memo = $profile;

        $this->audit->log(
            'updated',
            'Company',
            $before === []
                ? 'Company details saved (no changes)'
                : 'Company details updated: '.implode(', ', array_keys($before)),
            $profile,
            $before ?: null,
            $after ?: null,
        );

        return $profile;
    }

    /** Replace the logo. The previous file is discarded — one logo, no history. */
    public function setLogo(UploadedFile $logo): CompanyProfile
    {
        $profile = $this->get();
        $previous = $profile->logo_path;

        $profile->update(['logo_path' => $logo->store(self::LOGO_DIR, PrivateFileService::DISK)]);

        if ($previous) {
            Storage::disk(PrivateFileService::DISK)->delete($previous);
        }

        $this->memo = $profile;
        $this->audit->log('updated', 'Company', 'Updated the company logo', $profile);

        return $profile;
    }

    /** Back to a letterhead with no mark. */
    public function removeLogo(): CompanyProfile
    {
        $profile = $this->get();
        $previous = $profile->logo_path;

        if ($previous) {
            $profile->update(['logo_path' => null]);
            Storage::disk(PrivateFileService::DISK)->delete($previous);
            $this->audit->log('removed', 'Company', 'Removed the company logo', $profile);
        }

        $this->memo = $profile;

        return $profile;
    }
}
