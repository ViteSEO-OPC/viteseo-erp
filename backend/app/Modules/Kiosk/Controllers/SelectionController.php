<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Kiosk\Models\KioskSelection;
use App\Modules\Kiosk\Support\KioskCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Saved configurator selections (leads). Anyone who can run the configurator
 * (`kiosk.configure`) may save one; only BD/admin (`kiosk.selections.view` /
 * `.manage`) can list, read, or delete them.
 */
class SelectionController extends Controller
{
    /** GET /api/v1/kiosk/selections — captured leads for the current org. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('kiosk.selections.view'), 403);

        $selections = KioskSelection::query()
            ->with('creator:id,name')
            ->latest()
            ->get();

        return response()->json(['data' => $selections->map(fn (KioskSelection $s): array => $this->payload($s))]);
    }

    /** POST /api/v1/kiosk/selections — save a website selection (a lead). */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('kiosk.configure'), 403);

        $data = $request->validate([
            'prospect_name' => ['required', 'string', 'max:255'],
            'prospect_contact' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'business_type' => ['required', 'string', Rule::in(KioskCatalog::businessTypeKeys())],
            'website_type' => ['required', 'string', Rule::in(KioskCatalog::websiteTypeKeys())],
            'brand_kit' => ['required', 'string', Rule::in(KioskCatalog::brandKitKeys())],
            'typography' => ['nullable', 'string', Rule::in(KioskCatalog::typographyKeys())],
            'type_scale' => ['nullable', 'string', Rule::in(KioskCatalog::typeScaleKeys())],
            'design_style' => ['nullable', 'string', Rule::in(KioskCatalog::styleKeys())],
            'heading_style' => ['nullable', 'string', Rule::in(KioskCatalog::headingStyleKeys())],
            'radius' => ['nullable', 'string', Rule::in(KioskCatalog::radiusKeys())],
            'cta_hover' => ['nullable', 'string', Rule::in(KioskCatalog::ctaHoverKeys())],
            // Logo is an inline base64 data:image URI (see the add_logo migration).
            // ~700k chars ≈ a ~500KB image once base64-encoded — plenty for a logo,
            // small enough to keep the row and the exported HTML sane.
            'logo' => ['nullable', 'string', 'starts_with:data:image/', 'max:700000'],
            // Header + footer are site-wide slots; hero is now per-page (see below).
            'sections' => ['required', 'array'],
            'sections.header' => ['nullable', 'string', fn ($a, $v, $fail) => KioskCatalog::isValidSlotVariant('header', $v) ?: $fail('Invalid header layout.')],
            'sections.footer' => ['nullable', 'string', fn ($a, $v, $fail) => KioskCatalog::isValidSlotVariant('footer', $v) ?: $fail('Invalid footer layout.')],
            // Multi-page: each page has a name/slug, an optional hero, and its own
            // ordered content sections. (Optional `id`s are kept as-is for the UI.)
            'sections.pages' => ['required', 'array', 'min:1', function ($a, $value, $fail): void {
                $slugs = [];
                foreach ($value as $p) {
                    if (! is_array($p) || ! isset($p['name'], $p['slug']) || ! is_string($p['name']) || ! is_string($p['slug']) || $p['slug'] === '') {
                        $fail('Invalid page.');

                        return;
                    }
                    if (in_array($p['slug'], $slugs, true)) {
                        $fail('Duplicate page slug.');

                        return;
                    }
                    $slugs[] = $p['slug'];
                    if (! KioskCatalog::isValidSlotVariant('hero', $p['hero'] ?? null)) {
                        $fail('Invalid page hero.');

                        return;
                    }
                    foreach ($p['sections'] ?? [] as $sc) {
                        if (! is_array($sc) || ! isset($sc['section'], $sc['variant']) || ! KioskCatalog::isValidPageVariant($sc['section'], $sc['variant'])) {
                            $fail('Invalid page section or layout.');

                            return;
                        }
                    }
                }
            }],
        ]);

        $data['created_by_user_id'] = $request->user()->id;
        $selection = KioskSelection::create($data);

        return response()->json(['data' => $this->payload($selection->load('creator:id,name'))], 201);
    }

    /** GET /api/v1/kiosk/selections/{selection} — one lead (org-scoped by binding). */
    public function show(Request $request, KioskSelection $selection): JsonResponse
    {
        abort_unless($request->user()->can('kiosk.selections.view'), 403);

        return response()->json(['data' => $this->payload($selection->load('creator:id,name'))]);
    }

    /** DELETE /api/v1/kiosk/selections/{selection} — remove a lead. */
    public function destroy(Request $request, KioskSelection $selection): JsonResponse
    {
        abort_unless($request->user()->can('kiosk.selections.manage'), 403);

        $selection->delete();

        return response()->json(['data' => ['removed' => true]]);
    }

    /** @return array<string, mixed> */
    private function payload(KioskSelection $s): array
    {
        return [
            'id' => $s->id,
            'prospect_name' => $s->prospect_name,
            'prospect_contact' => $s->prospect_contact,
            'notes' => $s->notes,
            'business_type' => $s->business_type,
            'business_type_label' => KioskCatalog::businessTypeLabel($s->business_type),
            'website_type' => $s->website_type,
            'website_type_label' => KioskCatalog::websiteTypeLabel($s->website_type),
            'sections' => $s->sections,
            'brand_kit' => $s->brand_kit,
            'typography' => $s->typography,
            'type_scale' => $s->type_scale,
            'design_style' => $s->design_style,
            'heading_style' => $s->heading_style,
            'radius' => $s->radius,
            'cta_hover' => $s->cta_hover,
            'logo' => $s->logo,
            'created_by' => $s->creator?->name,
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
