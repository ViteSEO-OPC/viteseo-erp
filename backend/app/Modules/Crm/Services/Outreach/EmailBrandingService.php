<?php

namespace App\Modules\Crm\Services\Outreach;

use App\Models\Org;
use App\Modules\Crm\Models\EmailBranding;
use App\Support\CurrentOrg;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The organization's email branding — for now, its logo.
 *
 * Every design's logo block renders the logo the branding points at, at the moment the email is rendered.
 * Replacing the logo therefore reaches every design and every template that uses one, with nothing to copy
 * or regenerate: a logo has no plain-text form, so no template's stored body changes.
 */
class EmailBrandingService
{
    public function __construct(private readonly EmailAssetService $assets) {}

    /** The current organization's branding, or null if nothing has been set yet. Never writes. */
    public function current(): ?EmailBranding
    {
        return EmailBranding::query()->with('logo')->first();
    }

    /** Stores a new logo asset and points the organization's branding at it. The previous asset is kept. */
    public function uploadLogo(UploadedFile $file, string $crop, ?UploadedFile $rendition, ?int $actorId): EmailBranding
    {
        $asset = $this->assets->storeLogo($file, $crop, $rendition, $actorId);

        return DB::transaction(function () use ($asset, $actorId): EmailBranding {
            $branding = $this->forUpdate();
            $branding->update(['logo_asset_id' => $asset->id, 'updated_by' => $actorId]);

            return $branding->fresh('logo');
        });
    }

    /** Designs show no logo until a new one is uploaded. The asset itself is kept for emails already sent. */
    public function removeLogo(?int $actorId): ?EmailBranding
    {
        $branding = $this->current();
        $branding?->update(['logo_asset_id' => null, 'updated_by' => $actorId]);

        return $branding?->fresh('logo');
    }

    /**
     * The logo as a render needs it: its public URL, its PNG bytes and alt text — or null when the organization
     * has no logo, or its file is missing.
     *
     * @return array{url: string, png: string, alt: string}|null
     */
    public function logoForRender(): ?array
    {
        $logo = $this->current()?->logo;
        $png = $logo === null ? null : $this->assets->pngBytes($logo);
        if ($logo === null || $png === null) {
            return null;
        }

        $name = trim((string) Org::query()->whereKey(CurrentOrg::id())->value('name'));

        return ['url' => $this->assets->publicUrl($logo), 'png' => $png, 'alt' => $name === '' ? 'Logo' : $name];
    }

    /** The branding row, created the first time it is written. Two first writes cannot both create one. */
    private function forUpdate(): EmailBranding
    {
        $existing = EmailBranding::query()->lockForUpdate()->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn (): EmailBranding => EmailBranding::create([]));
        } catch (UniqueConstraintViolationException) {
            return EmailBranding::query()->lockForUpdate()->firstOrFail();
        }
    }
}
