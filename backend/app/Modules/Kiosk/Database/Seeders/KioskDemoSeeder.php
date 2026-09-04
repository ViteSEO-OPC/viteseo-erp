<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Database\Seeders;

use App\Models\Org;
use App\Models\User;
use App\Modules\Kiosk\Models\KioskSelection;
use App\Modules\Kiosk\Support\KioskCatalog;
use App\Support\CurrentOrg;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo Kiosk leads — 15 varied website selections spanning every hero/header/
 * footer + content-section variant, all theme knobs (colour incl. dark kits,
 * typography, size, style, heading, corners) and the new bands (stats/logos/cta),
 * so the Leads page, preview, and export have realistic data to try. Idempotent:
 * re-running replaces the demo rows. (Ana's Bakery is omitted so it can be created
 * by hand as a wizard test.)
 *
 * Run: php artisan db:seed --class="App\Modules\Kiosk\Database\Seeders\KioskDemoSeeder"
 */
class KioskDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::role('super_admin')->first() ?? User::query()->first();
        if (! $user) {
            $this->command?->warn('KioskDemoSeeder: no users found — seed the app first.');

            return;
        }

        $orgId = DB::table('org_user')->where('user_id', $user->id)->value('org_id')
            ?? Org::query()->min('id');
        if (! $orgId) {
            $this->command?->warn('KioskDemoSeeder: no org found for the user.');

            return;
        }

        CurrentOrg::set((int) $orgId);

        foreach ($this->samples() as $s) {
            KioskSelection::where('prospect_name', $s['prospect_name'])->forceDelete();
            $s['sections'] = $this->toMultipage($s['sections']);
            KioskSelection::create(array_merge($s, [
                'org_id' => $orgId,
                'created_by_user_id' => $user->id,
            ]));
        }

        $this->command?->info('KioskDemoSeeder: seeded '.count($this->samples())." demo leads for org #{$orgId}.");
    }

    /**
     * Wrap a sample's authored (flat) sections into the multi-page shape: the
     * curated blocks become the Home page (keeping its hero), plus About + Contact
     * pages from the blueprints so every demo lead shows the multi-page nav.
     *
     * @param  array<string, mixed>  $flat  {header, hero, footer, pages:[{section,variant}]}
     * @return array{header: ?string, footer: ?string, pages: list<array<string, mixed>>}
     */
    private function toMultipage(array $flat): array
    {
        $home = [
            'name' => 'Home',
            'slug' => 'home',
            'hero' => $flat['hero'] ?? null,
            'sections' => $flat['pages'] ?? [],
        ];

        $pages = [$home];
        foreach (['about', 'contact'] as $key) {
            $blueprint = KioskCatalog::pageBlueprint($key);
            if ($blueprint !== null) {
                $pages[] = $blueprint;
            }
        }

        return [
            'header' => $flat['header'] ?? null,
            'footer' => $flat['footer'] ?? null,
            'pages' => $pages,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function samples(): array
    {
        return [
            // Ana's Bakery is intentionally left out — Julius creates that one by
            // hand to test the wizard end-to-end. The rest are a showcase covering
            // every header/hero/footer + content-section variant, both styles
            // (clean/playful) and both heading styles (standard/handwritten).
            [
                'prospect_name' => 'Metro Fitness', 'prospect_contact' => 'hello@metrofit.ph',
                'business_type' => 'services', 'website_type' => 'landing',
                // Demo of the uploaded-logo feature: a small inline SVG wordmark (base64
                // data URI) that replaces the text logo in the header + footer.
                'logo' => 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxNTAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCAxNTAgNDAiPjxyZWN0IHg9IjAiIHk9IjQiIHdpZHRoPSIzMiIgaGVpZ2h0PSIzMiIgcng9IjgiIGZpbGw9IiMyRTdENkIiLz48dGV4dCB4PSIxNiIgeT0iMjciIGZvbnQtZmFtaWx5PSJBcmlhbCxIZWx2ZXRpY2Esc2Fucy1zZXJpZiIgZm9udC1zaXplPSIxNyIgZm9udC13ZWlnaHQ9IjcwMCIgZmlsbD0iI2ZmZiIgdGV4dC1hbmNob3I9Im1pZGRsZSI+TTwvdGV4dD48dGV4dCB4PSI0MCIgeT0iMjYiIGZvbnQtZmFtaWx5PSJBcmlhbCxIZWx2ZXRpY2Esc2Fucy1zZXJpZiIgZm9udC1zaXplPSIxNyIgZm9udC13ZWlnaHQ9IjcwMCIgZmlsbD0iIzFCMkEyNyI+TWV0cm88L3RleHQ+PC9zdmc+Cg==',
                'brand_kit' => 'tech_startup', 'typography' => 'space_inter', 'type_scale' => 'spacious', 'design_style' => 'playful', 'heading_style' => 'standard', 'radius' => 'pill',
                'sections' => ['header' => 'split', 'hero' => 'banner', 'footer' => 'cta', 'pages' => [
                    ['section' => 'logos', 'variant' => 'marquee'],
                    ['section' => 'services', 'variant' => 'steps'],
                    ['section' => 'stats', 'variant' => 'row'],
                    ['section' => 'pricing', 'variant' => 'single'],
                    ['section' => 'testimonials', 'variant' => 'single'],
                    ['section' => 'cta', 'variant' => 'split'],
                    ['section' => 'contact', 'variant' => 'boxed'],
                ]],
            ],
            [
                'prospect_name' => 'Bloom Cafe', 'prospect_contact' => 'orders@bloomcafe.ph',
                'business_type' => 'restaurant', 'website_type' => 'ecommerce',
                'brand_kit' => 'sunrise_studio', 'typography' => 'fraunces_nunito', 'type_scale' => 'spacious', 'design_style' => 'playful', 'heading_style' => 'standard',
                'sections' => ['header' => 'minimal', 'hero' => 'collage', 'footer' => 'columns', 'pages' => [
                    ['section' => 'logos', 'variant' => 'static'],
                    ['section' => 'products', 'variant' => 'featured'],
                    ['section' => 'gallery', 'variant' => 'spotlight'],
                    ['section' => 'pricing', 'variant' => 'table'],
                    ['section' => 'faq', 'variant' => 'accordion'],
                    ['section' => 'cta', 'variant' => 'centered'],
                ]],
            ],
            [
                'prospect_name' => 'Luxe Interiors', 'prospect_contact' => 'studio@luxeinteriors.ph',
                'business_type' => 'real_estate', 'website_type' => 'portfolio',
                'brand_kit' => 'midnight_luxe', 'typography' => 'dmserif_jost', 'type_scale' => 'standard', 'design_style' => 'clean', 'heading_style' => 'standard',
                'sections' => ['header' => 'centered', 'hero' => 'canvas', 'footer' => 'columns', 'pages' => [
                    ['section' => 'about', 'variant' => 'image_right'],
                    ['section' => 'stats', 'variant' => 'split'],
                    ['section' => 'gallery', 'variant' => 'masonry'],
                    ['section' => 'testimonials', 'variant' => 'grid'],
                    ['section' => 'contact', 'variant' => 'split'],
                ]],
            ],
            [
                'prospect_name' => 'Sunset Yoga Studio', 'prospect_contact' => 'namaste@sunsetyoga.ph',
                'business_type' => 'services', 'website_type' => 'booking',
                'brand_kit' => 'soft_wellness', 'typography' => 'quicksand_mulish', 'type_scale' => 'spacious', 'design_style' => 'playful', 'heading_style' => 'handwritten',
                'sections' => ['header' => 'centered', 'hero' => 'minimal', 'footer' => 'simple', 'pages' => [
                    ['section' => 'about', 'variant' => 'image_left'],
                    ['section' => 'services', 'variant' => 'reactive'],
                    ['section' => 'testimonials', 'variant' => 'single'],
                    ['section' => 'faq', 'variant' => 'list'],
                    ['section' => 'contact', 'variant' => 'hours'],
                ]],
            ],
            [
                'prospect_name' => 'TechNova Solutions', 'prospect_contact' => 'contact@technova.ph',
                'business_type' => 'corporate', 'website_type' => 'informational',
                'brand_kit' => 'corporate_navy', 'typography' => 'montserrat_open', 'type_scale' => 'compact', 'design_style' => 'clean', 'heading_style' => 'standard', 'radius' => 'sharp',
                'sections' => ['header' => 'classic', 'hero' => 'split', 'footer' => 'columns', 'pages' => [
                    ['section' => 'services', 'variant' => 'list'],
                    ['section' => 'about', 'variant' => 'stats'],
                    ['section' => 'blog', 'variant' => 'featured'],
                    ['section' => 'contact', 'variant' => 'split'],
                ]],
            ],
            [
                'prospect_name' => 'Ink & Needle Tattoo', 'prospect_contact' => 'book@inkandneedle.ph',
                'business_type' => 'services', 'website_type' => 'portfolio',
                'brand_kit' => 'crimson_ink', 'typography' => 'anton_roboto', 'type_scale' => 'spacious', 'design_style' => 'playful', 'heading_style' => 'handwritten',
                'sections' => ['header' => 'split', 'hero' => 'bleed', 'footer' => 'wordmark', 'pages' => [
                    ['section' => 'about', 'variant' => 'image_right'],
                    ['section' => 'gallery', 'variant' => 'mosaic'],
                    ['section' => 'testimonials', 'variant' => 'marquee'],
                    ['section' => 'contact', 'variant' => 'centered'],
                ]],
            ],
            [
                'prospect_name' => 'The Corner Bookshop', 'prospect_contact' => 'hello@cornerbooks.ph',
                'business_type' => 'retail', 'website_type' => 'ecommerce',
                'brand_kit' => 'warm_sand', 'typography' => 'lora_lato', 'type_scale' => 'standard', 'design_style' => 'clean', 'heading_style' => 'handwritten',
                'sections' => ['header' => 'classic', 'hero' => 'split', 'footer' => 'columns', 'pages' => [
                    ['section' => 'products', 'variant' => 'retail'],
                    ['section' => 'blog', 'variant' => 'list'],
                    ['section' => 'faq', 'variant' => 'columns'],
                    ['section' => 'contact', 'variant' => 'centered'],
                ]],
            ],
            [
                'prospect_name' => 'Zephyr Dental Clinic', 'prospect_contact' => 'smile@zephyrdental.ph',
                'business_type' => 'services', 'website_type' => 'booking',
                'brand_kit' => 'coastal_blue', 'typography' => 'poppins_inter', 'type_scale' => 'standard', 'design_style' => 'clean', 'heading_style' => 'standard', 'radius' => 'sharp',
                'sections' => ['header' => 'classic', 'hero' => 'split', 'footer' => 'columns', 'pages' => [
                    ['section' => 'logos', 'variant' => 'static'],
                    ['section' => 'services', 'variant' => 'reactive'],
                    ['section' => 'stats', 'variant' => 'row'],
                    ['section' => 'testimonials', 'variant' => 'cards'],
                    ['section' => 'contact', 'variant' => 'hours'],
                ]],
            ],
            [
                'prospect_name' => 'Volt Electric Cars', 'prospect_contact' => 'drive@voltcars.ph',
                'business_type' => 'retail', 'website_type' => 'informational',
                'brand_kit' => 'deep_space', 'typography' => 'sora_inter', 'type_scale' => 'spacious', 'design_style' => 'playful', 'heading_style' => 'standard', 'radius' => 'pill',
                'sections' => ['header' => 'split', 'hero' => 'collage', 'footer' => 'wordmark', 'pages' => [
                    ['section' => 'logos', 'variant' => 'marquee'],
                    ['section' => 'products', 'variant' => 'retail'],
                    ['section' => 'stats', 'variant' => 'split'],
                    ['section' => 'gallery', 'variant' => 'spotlight'],
                    ['section' => 'cta', 'variant' => 'split'],
                ]],
            ],
            [
                'prospect_name' => 'Golden Grain Bakery', 'prospect_contact' => 'orders@goldengrain.ph',
                'business_type' => 'restaurant', 'website_type' => 'ecommerce',
                'brand_kit' => 'warm_sand', 'typography' => 'lora_lato', 'type_scale' => 'standard', 'design_style' => 'clean', 'heading_style' => 'handwritten', 'radius' => 'soft',
                'sections' => ['header' => 'minimal', 'hero' => 'bleed', 'footer' => 'cta', 'pages' => [
                    ['section' => 'products', 'variant' => 'featured'],
                    ['section' => 'gallery', 'variant' => 'mosaic'],
                    ['section' => 'testimonials', 'variant' => 'marquee'],
                    ['section' => 'contact', 'variant' => 'boxed'],
                ]],
            ],
            [
                'prospect_name' => 'Nimbus Cloud', 'prospect_contact' => 'hello@nimbus.io',
                'business_type' => 'corporate', 'website_type' => 'landing',
                'brand_kit' => 'grape_soda', 'typography' => 'space_inter', 'type_scale' => 'standard', 'design_style' => 'clean', 'heading_style' => 'standard', 'radius' => 'soft',
                'sections' => ['header' => 'classic', 'hero' => 'left', 'footer' => 'columns', 'pages' => [
                    ['section' => 'logos', 'variant' => 'marquee'],
                    ['section' => 'services', 'variant' => 'steps'],
                    ['section' => 'stats', 'variant' => 'row'],
                    ['section' => 'pricing', 'variant' => 'table'],
                    ['section' => 'cta', 'variant' => 'centered'],
                ]],
            ],
            [
                'prospect_name' => 'Midnight Bar & Lounge', 'prospect_contact' => 'reserve@midnightlounge.ph',
                'business_type' => 'hospitality', 'website_type' => 'informational',
                'brand_kit' => 'midnight', 'typography' => 'dmserif_jost', 'type_scale' => 'spacious', 'design_style' => 'playful', 'heading_style' => 'standard', 'radius' => 'soft',
                'sections' => ['header' => 'centered', 'hero' => 'canvas', 'footer' => 'wordmark', 'pages' => [
                    ['section' => 'about', 'variant' => 'stats'],
                    ['section' => 'gallery', 'variant' => 'masonry'],
                    ['section' => 'testimonials', 'variant' => 'single'],
                    ['section' => 'contact', 'variant' => 'hours'],
                ]],
            ],
            [
                'prospect_name' => 'Bloom Florals', 'prospect_contact' => 'hello@bloomflorals.ph',
                'business_type' => 'retail', 'website_type' => 'ecommerce',
                'brand_kit' => 'sunrise_studio', 'typography' => 'fraunces_nunito', 'type_scale' => 'spacious', 'design_style' => 'playful', 'heading_style' => 'handwritten', 'radius' => 'pill',
                'sections' => ['header' => 'minimal', 'hero' => 'image', 'footer' => 'columns', 'pages' => [
                    ['section' => 'products', 'variant' => 'grid'],
                    ['section' => 'gallery', 'variant' => 'spotlight'],
                    ['section' => 'testimonials', 'variant' => 'grid'],
                    ['section' => 'cta', 'variant' => 'split'],
                ]],
            ],
            [
                'prospect_name' => 'Peak Legal Advisors', 'prospect_contact' => 'counsel@peaklegal.ph',
                'business_type' => 'corporate', 'website_type' => 'informational',
                'brand_kit' => 'corporate_navy', 'typography' => 'montserrat_open', 'type_scale' => 'compact', 'design_style' => 'clean', 'heading_style' => 'standard', 'radius' => 'sharp',
                'sections' => ['header' => 'classic', 'hero' => 'split', 'footer' => 'columns', 'pages' => [
                    ['section' => 'services', 'variant' => 'list'],
                    ['section' => 'about', 'variant' => 'centered'],
                    ['section' => 'stats', 'variant' => 'split'],
                    ['section' => 'faq', 'variant' => 'columns'],
                    ['section' => 'contact', 'variant' => 'split'],
                ]],
            ],
            [
                // Showcases the new (v5) kit + variants: Clinic Clean kit, video hero,
                // Team section, and the Contact "map" variant.
                'prospect_name' => 'Bright Smile Dental', 'prospect_contact' => 'care@brightsmile.ph',
                'business_type' => 'services', 'website_type' => 'booking',
                'brand_kit' => 'clinic_clean', 'type_scale' => 'standard', 'design_style' => 'clean', 'heading_style' => 'standard', 'radius' => 'soft',
                'sections' => ['header' => 'classic', 'hero' => 'video', 'footer' => 'columns', 'pages' => [
                    ['section' => 'about', 'variant' => 'image_right'],
                    ['section' => 'services', 'variant' => 'cards'],
                    ['section' => 'team', 'variant' => 'grid'],
                    ['section' => 'testimonials', 'variant' => 'cards'],
                    ['section' => 'contact', 'variant' => 'map'],
                ]],
            ],
        ];
    }
}
