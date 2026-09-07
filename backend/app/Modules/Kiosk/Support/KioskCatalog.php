<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Support;

/**
 * The Kiosk option catalog — the pickable choices in the website configurator:
 * business types, website types, section variants (the building blocks), and
 * brand kits (palette + font pairing). Kept as a registry for v1 so the team can
 * expand it fast; can graduate to DB-managed later. Working defaults — the BD/
 * design team refines the copy, variants, and palettes.
 */
class KioskCatalog
{
    /** @return list<array{key: string, label: string, description: string}> */
    public static function businessTypes(): array
    {
        return [
            ['key' => 'restaurant', 'label' => 'Restaurant / Food', 'description' => 'Cafés, restaurants, bakeries & food businesses'],
            ['key' => 'retail', 'label' => 'Retail / Shop', 'description' => 'Shops & stores selling products'],
            ['key' => 'services', 'label' => 'Services', 'description' => 'Salons, clinics, repair & professional services'],
            ['key' => 'real_estate', 'label' => 'Real Estate', 'description' => 'Property listings, agents & developers'],
            ['key' => 'hospitality', 'label' => 'Hospitality / Events', 'description' => 'Hotels, resorts, events & venues'],
            ['key' => 'education', 'label' => 'Education / Training', 'description' => 'Schools, tutorials & training centers'],
            ['key' => 'nonprofit', 'label' => 'Nonprofit / Church', 'description' => 'Churches, charities & community groups'],
            ['key' => 'personal', 'label' => 'Personal / Creative', 'description' => 'Freelancers, artists, coaches & creators'],
            ['key' => 'corporate', 'label' => 'Corporate / B2B', 'description' => 'Companies, agencies & B2B businesses'],
        ];
    }

    /** @return list<array{key: string, label: string, description: string}> */
    public static function websiteTypes(): array
    {
        return [
            ['key' => 'informational', 'label' => 'Informational', 'description' => 'Tell people who you are — a simple, trustworthy company profile. Best if you just want to be found online.'],
            ['key' => 'ecommerce', 'label' => 'E-commerce', 'description' => 'Sell products online with a cart & checkout. Best if you want to earn directly from your website.'],
            ['key' => 'portfolio', 'label' => 'Portfolio', 'description' => 'Show off your work with a visual gallery. Best for creatives, studios & professionals.'],
            ['key' => 'landing', 'label' => 'Landing Page', 'description' => 'One focused page that turns visitors into leads. Best for a promo, product launch, or ads.'],
            ['key' => 'booking', 'label' => 'Booking / Appointment', 'description' => 'Let clients book appointments or reserve online. Best for services, clinics & salons.'],
            ['key' => 'blog', 'label' => 'Blog / Content', 'description' => 'Share articles, updates & stories. Best if you post content regularly to grow an audience.'],
        ];
    }

    /**
     * The fixed single-select slots (header / hero / footer) and their layout
     * variants. Each variant is a distinct layout the HTML engine can render, so
     * two sites rarely look alike.
     *
     * @return list<array{slot: string, label: string, variants: list<array{key: string, label: string}>}>
     */
    public static function sections(): array
    {
        return [
            ['slot' => 'header', 'label' => 'Header', 'variants' => [
                ['key' => 'minimal', 'label' => 'Minimal'],
                ['key' => 'classic', 'label' => 'Classic (logo + nav)'],
                ['key' => 'centered', 'label' => 'Centered'],
                ['key' => 'split', 'label' => 'Logo + CTA'],
            ]],
            ['slot' => 'hero', 'label' => 'Hero', 'variants' => [
                ['key' => 'image', 'label' => 'Full image + CTA'],
                ['key' => 'split', 'label' => 'Split image / text'],
                ['key' => 'minimal', 'label' => 'Minimal headline'],
                ['key' => 'banner', 'label' => 'Banner with overlay'],
                ['key' => 'left', 'label' => 'Left-aligned'],
                ['key' => 'bleed', 'label' => 'Off-frame subject'],
                ['key' => 'canvas', 'label' => 'Editorial canvas'],
                ['key' => 'collage', 'label' => 'Collage + parallax'],
                ['key' => 'video', 'label' => 'Video background'],
            ]],
            ['slot' => 'footer', 'label' => 'Footer', 'variants' => [
                ['key' => 'simple', 'label' => 'Simple'],
                ['key' => 'columns', 'label' => 'Columns'],
                ['key' => 'cta', 'label' => 'Call-to-action + links'],
                ['key' => 'wordmark', 'label' => 'Oversized wordmark'],
            ]],
        ];
    }

    /**
     * Page/content sections — each toggled on/off, and when on, given ONE of its
     * own layout variants. This is what makes each site unique.
     *
     * @return list<array{key: string, label: string, variants: list<array{key: string, label: string}>}>
     */
    public static function pageSections(): array
    {
        return [
            ['key' => 'about', 'label' => 'About', 'variants' => [
                ['key' => 'image_left', 'label' => 'Image left'],
                ['key' => 'image_right', 'label' => 'Image right'],
                ['key' => 'centered', 'label' => 'Centered'],
                ['key' => 'stats', 'label' => 'With stats'],
            ]],
            ['key' => 'services', 'label' => 'Services', 'variants' => [
                ['key' => 'grid', 'label' => '3-column grid'],
                ['key' => 'list', 'label' => 'Stacked list'],
                ['key' => 'cards', 'label' => 'Icon cards'],
                ['key' => 'steps', 'label' => 'Numbered steps'],
                ['key' => 'reactive', 'label' => 'Index + reactive image'],
            ]],
            ['key' => 'products', 'label' => 'Products', 'variants' => [
                ['key' => 'grid', 'label' => 'Product grid'],
                ['key' => 'featured', 'label' => 'Featured + grid'],
                ['key' => 'list', 'label' => 'List rows'],
                ['key' => 'retail', 'label' => 'Retail (ribbon + cart)'],
            ]],
            ['key' => 'gallery', 'label' => 'Gallery', 'variants' => [
                ['key' => 'grid', 'label' => 'Even grid'],
                ['key' => 'masonry', 'label' => 'Masonry'],
                ['key' => 'spotlight', 'label' => 'Spotlight + grid'],
                ['key' => 'mosaic', 'label' => 'Asymmetric mosaic'],
            ]],
            ['key' => 'pricing', 'label' => 'Pricing', 'variants' => [
                ['key' => 'cards', 'label' => '3 tier cards'],
                ['key' => 'table', 'label' => 'Comparison table'],
                ['key' => 'single', 'label' => 'Single offer'],
            ]],
            ['key' => 'testimonials', 'label' => 'Testimonials', 'variants' => [
                ['key' => 'cards', 'label' => 'Quote cards'],
                ['key' => 'single', 'label' => 'Single spotlight'],
                ['key' => 'grid', 'label' => '3-column grid'],
                ['key' => 'marquee', 'label' => 'Marquee'],
            ]],
            ['key' => 'faq', 'label' => 'FAQ', 'variants' => [
                ['key' => 'list', 'label' => 'Open list'],
                ['key' => 'accordion', 'label' => 'Accordion'],
                ['key' => 'columns', 'label' => 'Two columns'],
            ]],
            ['key' => 'blog', 'label' => 'Blog', 'variants' => [
                ['key' => 'cards', 'label' => 'Post cards'],
                ['key' => 'list', 'label' => 'Post list'],
                ['key' => 'featured', 'label' => 'Featured + list'],
            ]],
            ['key' => 'team', 'label' => 'Team', 'variants' => [
                ['key' => 'grid', 'label' => 'Photo grid'],
                ['key' => 'cards', 'label' => 'Bio cards'],
            ]],
            ['key' => 'contact', 'label' => 'Contact', 'variants' => [
                ['key' => 'split', 'label' => 'Form + info'],
                ['key' => 'centered', 'label' => 'Centered form'],
                ['key' => 'boxed', 'label' => 'Boxed card'],
                ['key' => 'hours', 'label' => 'Form + hours (open now)'],
                ['key' => 'map', 'label' => 'Form + map'],
            ]],
            ['key' => 'stats', 'label' => 'Stats', 'variants' => [
                ['key' => 'row', 'label' => '4 metrics (count-up)'],
                ['key' => 'split', 'label' => 'Metrics + credibility'],
            ]],
            ['key' => 'logos', 'label' => 'Logos / Trust', 'variants' => [
                ['key' => 'static', 'label' => 'Grayscale strip'],
                ['key' => 'marquee', 'label' => 'Infinite marquee'],
            ]],
            ['key' => 'cta', 'label' => 'Call-to-action band', 'variants' => [
                ['key' => 'centered', 'label' => 'Centered band'],
                ['key' => 'split', 'label' => 'Heading + button'],
            ]],
        ];
    }

    /**
     * Brand kits — palette + font pairing applied to the preview via CSS vars.
     *
     * @return list<array{key: string, label: string, palette: array{primary: string, accent: string, bg: string, text: string}, fonts: array{heading: string, body: string}}>
     */
    public static function brandKits(): array
    {
        return [
            [
                'key' => 'fresh_mint', 'label' => 'Fresh Mint',
                'palette' => ['primary' => '#2E7D6B', 'accent' => '#F2A65A', 'bg' => '#F7FAF9', 'text' => '#1B2A27'],
                'fonts' => ['heading' => 'Poppins', 'body' => 'Inter'],
            ],
            [
                'key' => 'sunset_coral', 'label' => 'Sunset Coral',
                'palette' => ['primary' => '#E76F51', 'accent' => '#264653', 'bg' => '#FFF8F5', 'text' => '#2B2420'],
                'fonts' => ['heading' => 'Playfair Display', 'body' => 'Source Sans 3'],
            ],
            [
                'key' => 'corporate_navy', 'label' => 'Corporate Navy',
                'palette' => ['primary' => '#1D3557', 'accent' => '#457B9D', 'bg' => '#F5F7FA', 'text' => '#1A202C'],
                'fonts' => ['heading' => 'Montserrat', 'body' => 'Inter'],
            ],
            [
                'key' => 'bold_berry', 'label' => 'Bold Berry',
                'palette' => ['primary' => '#7B2D8E', 'accent' => '#F2C14E', 'bg' => '#FBF6FB', 'text' => '#2A1B2E'],
                'fonts' => ['heading' => 'Montserrat', 'body' => 'Inter'],
            ],
            [
                'key' => 'warm_sand', 'label' => 'Warm Sand',
                'palette' => ['primary' => '#B5651D', 'accent' => '#6B8E23', 'bg' => '#FBF7F0', 'text' => '#2E2620'],
                'fonts' => ['heading' => 'Playfair Display', 'body' => 'Source Sans 3'],
            ],
            [
                'key' => 'ocean_teal', 'label' => 'Ocean Teal',
                'palette' => ['primary' => '#0E7C86', 'accent' => '#F4845F', 'bg' => '#F2FAFB', 'text' => '#14343A'],
                'fonts' => ['heading' => 'Poppins', 'body' => 'Inter'],
            ],
            [
                'key' => 'modern_grotesk', 'label' => 'Modern Grotesk',
                'palette' => ['primary' => '#111827', 'accent' => '#22D3EE', 'bg' => '#F9FAFB', 'text' => '#111827'],
                'fonts' => ['heading' => 'Space Grotesk', 'body' => 'Inter'],
            ],
            [
                'key' => 'editorial_serif', 'label' => 'Editorial Serif',
                'palette' => ['primary' => '#5B3A29', 'accent' => '#C79A5B', 'bg' => '#FBF9F5', 'text' => '#2B2016'],
                'fonts' => ['heading' => 'Merriweather', 'body' => 'Source Sans 3'],
            ],
            [
                'key' => 'playful_pop', 'label' => 'Playful Pop',
                'palette' => ['primary' => '#EF476F', 'accent' => '#06D6A0', 'bg' => '#FFF9FB', 'text' => '#26212A'],
                'fonts' => ['heading' => 'Poppins', 'body' => 'Nunito'],
            ],
            [
                'key' => 'midnight_luxe', 'label' => 'Midnight Luxe',
                'palette' => ['primary' => '#1A2238', 'accent' => '#C9A227', 'bg' => '#FAF8F2', 'text' => '#1A2238'],
                'fonts' => ['heading' => 'DM Serif Display', 'body' => 'Jost'],
            ],
            [
                'key' => 'sunrise_studio', 'label' => 'Sunrise Studio',
                'palette' => ['primary' => '#FF6B6B', 'accent' => '#FFB86B', 'bg' => '#FFF8F3', 'text' => '#3A2E2A'],
                'fonts' => ['heading' => 'Fraunces', 'body' => 'Nunito Sans'],
            ],
            [
                'key' => 'tech_startup', 'label' => 'Tech Startup',
                'palette' => ['primary' => '#4F46E5', 'accent' => '#06B6D4', 'bg' => '#F8FAFF', 'text' => '#1E1B3A'],
                'fonts' => ['heading' => 'Sora', 'body' => 'Inter'],
            ],
            [
                'key' => 'artisan', 'label' => 'Artisan',
                'palette' => ['primary' => '#6B705C', 'accent' => '#CB997E', 'bg' => '#FBF9F4', 'text' => '#2C2A26'],
                'fonts' => ['heading' => 'Cormorant Garamond', 'body' => 'Karla'],
            ],
            [
                'key' => 'bold_agency', 'label' => 'Bold Agency',
                'palette' => ['primary' => '#111111', 'accent' => '#E63946', 'bg' => '#F7F7F7', 'text' => '#111111'],
                'fonts' => ['heading' => 'Anton', 'body' => 'Roboto'],
            ],
            [
                'key' => 'soft_wellness', 'label' => 'Soft Wellness',
                'palette' => ['primary' => '#7C9A92', 'accent' => '#E4B7A0', 'bg' => '#F6FAF8', 'text' => '#2F3A36'],
                'fonts' => ['heading' => 'Quicksand', 'body' => 'Mulish'],
            ],
            [
                'key' => 'classic_law', 'label' => 'Classic Law',
                'palette' => ['primary' => '#6E1423', 'accent' => '#B08D57', 'bg' => '#FBF7F4', 'text' => '#241B1C'],
                'fonts' => ['heading' => 'Playfair Display', 'body' => 'Lato'],
            ],
            [
                'key' => 'vibrant_retail', 'label' => 'Vibrant Retail',
                'palette' => ['primary' => '#D6336C', 'accent' => '#FCC419', 'bg' => '#FFF9FC', 'text' => '#2B1E27'],
                'fonts' => ['heading' => 'Montserrat', 'body' => 'Open Sans'],
            ],
            [
                'key' => 'editorial_modern', 'label' => 'Editorial Modern',
                'palette' => ['primary' => '#2B2B2B', 'accent' => '#E8A317', 'bg' => '#FAFAF8', 'text' => '#1C1C1C'],
                'fonts' => ['heading' => 'Libre Baskerville', 'body' => 'Work Sans'],
            ],
            [
                'key' => 'coastal_blue', 'label' => 'Coastal Blue',
                'palette' => ['primary' => '#2A6F97', 'accent' => '#F6BD60', 'bg' => '#F4F9FC', 'text' => '#14313D'],
                'fonts' => ['heading' => 'Poppins', 'body' => 'Inter'],
            ],
            [
                'key' => 'forest_green', 'label' => 'Forest Green',
                'palette' => ['primary' => '#386641', 'accent' => '#E9C46A', 'bg' => '#F5F9F3', 'text' => '#1E2A22'],
                'fonts' => ['heading' => 'Montserrat', 'body' => 'Open Sans'],
            ],
            [
                'key' => 'grape_soda', 'label' => 'Grape Soda',
                'palette' => ['primary' => '#6247AA', 'accent' => '#FF9F1C', 'bg' => '#FAF7FF', 'text' => '#241B33'],
                'fonts' => ['heading' => 'Sora', 'body' => 'Inter'],
            ],
            [
                'key' => 'midnight', 'label' => 'Midnight (dark)', 'dark' => true,
                'palette' => ['primary' => '#B8860B', 'accent' => '#E5C15A', 'bg' => '#14151A', 'text' => '#ECE6D6'],
                'fonts' => ['heading' => 'Playfair Display', 'body' => 'Source Sans 3'],
            ],
            [
                'key' => 'neon_noir', 'label' => 'Neon Noir (dark)', 'dark' => true,
                'palette' => ['primary' => '#7C3AED', 'accent' => '#CCFF00', 'bg' => '#121212', 'text' => '#F2F2F2'],
                'fonts' => ['heading' => 'Anton', 'body' => 'Roboto'],
            ],
            [
                'key' => 'deep_space', 'label' => 'Deep Space (dark)', 'dark' => true,
                'palette' => ['primary' => '#4F46E5', 'accent' => '#22D3EE', 'bg' => '#0B1120', 'text' => '#E5E9F0'],
                'fonts' => ['heading' => 'Sora', 'body' => 'Inter'],
            ],
            [
                'key' => 'crimson_ink', 'label' => 'Crimson Ink (dark)', 'dark' => true,
                'palette' => ['primary' => '#E63946', 'accent' => '#FF8FA3', 'bg' => '#0A0A0A', 'text' => '#EDEDED'],
                'fonts' => ['heading' => 'Bebas Neue', 'body' => 'Inter'],
            ],
            [
                // Medical / dental clinics — trustworthy blue on clean white.
                'key' => 'clinic_clean', 'label' => 'Clinic Clean',
                'palette' => ['primary' => '#2563EB', 'accent' => '#38BDF8', 'bg' => '#F8FAFC', 'text' => '#1E293B'],
                'fonts' => ['heading' => 'Manrope', 'body' => 'Inter'],
            ],
            [
                // Coffee shops / bakeries — warm coffee brown on cream, editorial serif.
                'key' => 'cafe_warm', 'label' => 'Cafe Warm',
                'palette' => ['primary' => '#6F4E37', 'accent' => '#C8A27C', 'bg' => '#FBF6EF', 'text' => '#2A211B'],
                'fonts' => ['heading' => 'Playfair Display', 'body' => 'Source Sans 3'],
            ],
            [
                // Grocery / organic / farm — leafy green with a harvest-gold accent.
                'key' => 'fresh_market', 'label' => 'Fresh Market',
                'palette' => ['primary' => '#4B7F52', 'accent' => '#E9C46A', 'bg' => '#F6F8F1', 'text' => '#24301F'],
                'fonts' => ['heading' => 'Rubik', 'body' => 'Inter'],
            ],
            [
                // Automotive / hardware / repair — bold charcoal with a safety-orange accent.
                'key' => 'auto_bold', 'label' => 'Auto Bold',
                'palette' => ['primary' => '#1F2937', 'accent' => '#F97316', 'bg' => '#F5F5F4', 'text' => '#18181B'],
                'fonts' => ['heading' => 'Archivo', 'body' => 'Inter'],
            ],
        ];
    }

    /**
     * Typography pairings (heading + body), chosen INDEPENDENTLY of colour so the
     * same palette can feel totally different. All Google Fonts (loaded
     * dynamically) — adding one is just a new row.
     *
     * @return list<array{key: string, label: string, heading: string, body: string}>
     */
    public static function typographies(): array
    {
        return [
            ['key' => 'poppins_inter', 'label' => 'Poppins + Inter', 'heading' => 'Poppins', 'body' => 'Inter'],
            ['key' => 'playfair_source', 'label' => 'Playfair + Source Sans', 'heading' => 'Playfair Display', 'body' => 'Source Sans 3'],
            ['key' => 'montserrat_open', 'label' => 'Montserrat + Open Sans', 'heading' => 'Montserrat', 'body' => 'Open Sans'],
            ['key' => 'dmserif_jost', 'label' => 'DM Serif + Jost', 'heading' => 'DM Serif Display', 'body' => 'Jost'],
            ['key' => 'space_inter', 'label' => 'Space Grotesk + Inter', 'heading' => 'Space Grotesk', 'body' => 'Inter'],
            ['key' => 'fraunces_nunito', 'label' => 'Fraunces + Nunito Sans', 'heading' => 'Fraunces', 'body' => 'Nunito Sans'],
            ['key' => 'sora_inter', 'label' => 'Sora + Inter', 'heading' => 'Sora', 'body' => 'Inter'],
            ['key' => 'cormorant_karla', 'label' => 'Cormorant + Karla', 'heading' => 'Cormorant Garamond', 'body' => 'Karla'],
            ['key' => 'anton_roboto', 'label' => 'Anton + Roboto', 'heading' => 'Anton', 'body' => 'Roboto'],
            ['key' => 'quicksand_mulish', 'label' => 'Quicksand + Mulish', 'heading' => 'Quicksand', 'body' => 'Mulish'],
            ['key' => 'libre_work', 'label' => 'Libre Baskerville + Work Sans', 'heading' => 'Libre Baskerville', 'body' => 'Work Sans'],
            ['key' => 'bebas_inter', 'label' => 'Bebas Neue + Inter', 'heading' => 'Bebas Neue', 'body' => 'Inter'],
            ['key' => 'lora_lato', 'label' => 'Lora + Lato', 'heading' => 'Lora', 'body' => 'Lato'],
            ['key' => 'archivo_inter', 'label' => 'Archivo + Inter', 'heading' => 'Archivo', 'body' => 'Inter'],
            ['key' => 'abril_poppins', 'label' => 'Abril Fatface + Poppins', 'heading' => 'Abril Fatface', 'body' => 'Poppins'],
            ['key' => 'oswald_roboto', 'label' => 'Oswald + Roboto', 'heading' => 'Oswald', 'body' => 'Roboto'],
            ['key' => 'raleway_roboto', 'label' => 'Raleway + Roboto', 'heading' => 'Raleway', 'body' => 'Roboto'],
            ['key' => 'rubik_inter', 'label' => 'Rubik + Inter', 'heading' => 'Rubik', 'body' => 'Inter'],
            ['key' => 'josefin_lato', 'label' => 'Josefin Sans + Lato', 'heading' => 'Josefin Sans', 'body' => 'Lato'],
            ['key' => 'spectral_inter', 'label' => 'Spectral + Inter', 'heading' => 'Spectral', 'body' => 'Inter'],
        ];
    }

    /**
     * Overall type-size scale — how big the whole typography reads.
     *
     * @return list<array{key: string, label: string, description: string}>
     */
    public static function typeScales(): array
    {
        return [
            ['key' => 'compact', 'label' => 'Compact', 'description' => 'Smaller & tighter — more content per screen'],
            ['key' => 'standard', 'label' => 'Standard', 'description' => 'Balanced default'],
            ['key' => 'spacious', 'label' => 'Spacious', 'description' => 'Big & bold — creative statement look'],
        ];
    }

    /**
     * Design style / vibe applied across the whole site.
     *
     * @return list<array{key: string, label: string, description: string}>
     */
    public static function styles(): array
    {
        return [
            ['key' => 'clean', 'label' => 'Clean', 'description' => 'Minimal & professional'],
            ['key' => 'playful', 'label' => 'Playful', 'description' => 'Creative — gradient blobs, colour & motion'],
        ];
    }

    /**
     * Heading treatment — printed vs a hand-drawn, signature-style look (human
     * touch) with the body kept in a readable font.
     *
     * @return list<array{key: string, label: string, description: string}>
     */
    public static function headingStyles(): array
    {
        return [
            ['key' => 'standard', 'label' => 'Standard', 'description' => 'Clean printed headings'],
            ['key' => 'handwritten', 'label' => 'Handwritten', 'description' => 'Hand-drawn, signature-style headings + underline'],
        ];
    }

    /** @return list<string> */
    public static function typographyKeys(): array
    {
        return array_column(self::typographies(), 'key');
    }

    /** @return list<string> */
    public static function typeScaleKeys(): array
    {
        return array_column(self::typeScales(), 'key');
    }

    /** @return list<string> */
    public static function styleKeys(): array
    {
        return array_column(self::styles(), 'key');
    }

    /** @return list<string> */
    public static function headingStyleKeys(): array
    {
        return array_column(self::headingStyles(), 'key');
    }

    /**
     * Corner style — the border-radius personality (a strong, validated theme
     * lever: sharp = corporate, soft = modern, pill = premium/playful).
     *
     * @return list<array{key: string, label: string, description: string}>
     */
    public static function radii(): array
    {
        return [
            ['key' => 'sharp', 'label' => 'Sharp', 'description' => 'Squared corners — bold & corporate'],
            ['key' => 'soft', 'label' => 'Soft', 'description' => 'Rounded — friendly & modern'],
            ['key' => 'pill', 'label' => 'Pill', 'description' => 'Extra-round — premium & playful'],
        ];
    }

    /** @return list<string> */
    public static function radiusKeys(): array
    {
        return array_column(self::radii(), 'key');
    }

    /**
     * CTA button hover effect — how every call-to-action button animates on
     * hover. One global choice applied site-wide (like the other design knobs).
     * `wipe` is the long-standing default look; `none` keeps just the base lift.
     *
     * @return list<array{key: string, label: string, description: string}>
     */
    public static function ctaHovers(): array
    {
        return [
            ['key' => 'none', 'label' => 'None', 'description' => 'Simple lift only — no animation'],
            ['key' => 'wipe', 'label' => 'Wipe', 'description' => 'A colour fill sweeps across the button'],
            ['key' => 'flip', 'label' => 'Flip', 'description' => 'The label flips up to a fresh copy'],
            ['key' => 'gradient', 'label' => 'Gradient swipe', 'description' => 'An animated gradient slides through'],
            ['key' => 'glow', 'label' => 'Glow', 'description' => 'An accent glow blooms around the button'],
            ['key' => 'shine', 'label' => 'Shine', 'description' => 'A light sheen sweeps across the surface'],
        ];
    }

    /** @return list<string> */
    public static function ctaHoverKeys(): array
    {
        return array_column(self::ctaHovers(), 'key');
    }

    /**
     * Addable PAGE blueprints — a site is one-or-more pages (Home / About /
     * Contact / Services / Shop …), each with its own optional hero + a sensible
     * default set of content sections. The wizard uses these to (a) offer an "Add
     * page" palette and (b) seed niche defaults. Section (section,variant) pairs
     * here must all be valid pageSections.
     *
     * @return list<array{key: string, name: string, slug: string, hero: ?string, sections: list<array{section: string, variant: string}>}>
     */
    public static function pageBlueprints(): array
    {
        return [
            ['key' => 'home', 'name' => 'Home', 'slug' => 'home', 'hero' => 'image', 'sections' => [
                ['section' => 'about', 'variant' => 'image_right'],
                ['section' => 'testimonials', 'variant' => 'cards'],
                ['section' => 'cta', 'variant' => 'centered'],
            ]],
            ['key' => 'about', 'name' => 'About', 'slug' => 'about', 'hero' => null, 'sections' => [
                ['section' => 'about', 'variant' => 'stats'],
                ['section' => 'team', 'variant' => 'grid'],
            ]],
            ['key' => 'services', 'name' => 'Services', 'slug' => 'services', 'hero' => null, 'sections' => [
                ['section' => 'services', 'variant' => 'grid'],
                ['section' => 'pricing', 'variant' => 'cards'],
                ['section' => 'cta', 'variant' => 'centered'],
            ]],
            ['key' => 'shop', 'name' => 'Shop', 'slug' => 'shop', 'hero' => null, 'sections' => [
                ['section' => 'products', 'variant' => 'grid'],
            ]],
            ['key' => 'gallery', 'name' => 'Gallery', 'slug' => 'gallery', 'hero' => null, 'sections' => [
                ['section' => 'gallery', 'variant' => 'grid'],
            ]],
            ['key' => 'pricing', 'name' => 'Pricing', 'slug' => 'pricing', 'hero' => null, 'sections' => [
                ['section' => 'pricing', 'variant' => 'cards'],
                ['section' => 'faq', 'variant' => 'list'],
            ]],
            ['key' => 'contact', 'name' => 'Contact', 'slug' => 'contact', 'hero' => null, 'sections' => [
                ['section' => 'contact', 'variant' => 'split'],
            ]],
            ['key' => 'faq', 'name' => 'FAQ', 'slug' => 'faq', 'hero' => null, 'sections' => [
                ['section' => 'faq', 'variant' => 'accordion'],
            ]],
            ['key' => 'blog', 'name' => 'Blog', 'slug' => 'blog', 'hero' => null, 'sections' => [
                ['section' => 'blog', 'variant' => 'cards'],
            ]],
            ['key' => 'team', 'name' => 'Team', 'slug' => 'team', 'hero' => null, 'sections' => [
                ['section' => 'team', 'variant' => 'grid'],
            ]],
        ];
    }

    /** One page blueprint by key (a fresh copy ready to drop into a selection). */
    public static function pageBlueprint(string $key): ?array
    {
        foreach (self::pageBlueprints() as $b) {
            if ($b['key'] === $key) {
                return ['name' => $b['name'], 'slug' => $b['slug'], 'hero' => $b['hero'], 'sections' => $b['sections']];
            }
        }

        return null;
    }

    /**
     * The default set of pages for a business + website niche (Hybrid onboarding:
     * these are the starting pages; the user can add/remove afterward). Returns
     * full page objects assembled from the blueprints.
     *
     * @return list<array{name: string, slug: string, hero: ?string, sections: list<array{section: string, variant: string}>}>
     */
    public static function defaultPages(?string $businessType, ?string $websiteType): array
    {
        // Landing sites stay lean; otherwise Home + a niche "primary" page + About + Contact.
        if ($websiteType === 'landing') {
            $keys = ['home', 'contact'];
        } else {
            $primary = match ($websiteType) {
                'ecommerce' => 'shop',
                'portfolio' => 'gallery',
                'booking' => 'services',
                'blog' => 'blog',
                default => match ($businessType) {
                    'retail' => 'shop',
                    'services', 'real_estate', 'education' => 'services',
                    'restaurant', 'hospitality', 'personal' => 'gallery',
                    default => null,
                },
            };
            $keys = array_values(array_unique(array_filter(['home', $primary, 'about', 'contact'])));
        }

        $pages = [];
        foreach ($keys as $k) {
            $b = self::pageBlueprint($k);
            if ($b !== null) {
                $pages[] = $b;
            }
        }

        return $pages;
    }

    /** @return list<string> */
    public static function businessTypeKeys(): array
    {
        return array_column(self::businessTypes(), 'key');
    }

    /** @return list<string> */
    public static function websiteTypeKeys(): array
    {
        return array_column(self::websiteTypes(), 'key');
    }

    /** @return list<string> */
    public static function brandKitKeys(): array
    {
        return array_column(self::brandKits(), 'key');
    }

    /** Valid variant keys for a fixed slot (header/hero/footer). @return list<string> */
    public static function slotVariantKeys(string $slot): array
    {
        foreach (self::sections() as $s) {
            if ($s['slot'] === $slot) {
                return array_column($s['variants'], 'key');
            }
        }

        return [];
    }

    /** @return list<string> The page-section keys (about, services, …). */
    public static function pageSectionKeys(): array
    {
        return array_column(self::pageSections(), 'key');
    }

    /** Is $variant a valid layout for page section $section? */
    public static function isValidPageVariant(string $section, string $variant): bool
    {
        foreach (self::pageSections() as $p) {
            if ($p['key'] === $section) {
                return in_array($variant, array_column($p['variants'], 'key'), true);
            }
        }

        return false;
    }

    /** Is $variant valid for a fixed slot (or null/empty for none)? */
    public static function isValidSlotVariant(string $slot, ?string $variant): bool
    {
        return $variant === null || $variant === '' || in_array($variant, self::slotVariantKeys($slot), true);
    }

    /** Human label for a business type key (falls back to the key). */
    public static function businessTypeLabel(string $key): string
    {
        foreach (self::businessTypes() as $b) {
            if ($b['key'] === $key) {
                return $b['label'];
            }
        }

        return $key;
    }

    /** Human label for a website type key (falls back to the key). */
    public static function websiteTypeLabel(string $key): string
    {
        foreach (self::websiteTypes() as $w) {
            if ($w['key'] === $key) {
                return $w['label'];
            }
        }

        return $key;
    }

    /** @return array<string, mixed> The full catalog for the configurator. */
    public static function all(): array
    {
        return [
            'business_types' => self::businessTypes(),
            'website_types' => self::websiteTypes(),
            'sections' => self::sections(),
            'page_sections' => self::pageSections(),
            'brand_kits' => self::brandKits(),
            'typographies' => self::typographies(),
            'type_scales' => self::typeScales(),
            'styles' => self::styles(),
            'heading_styles' => self::headingStyles(),
            'radii' => self::radii(),
            'cta_hovers' => self::ctaHovers(),
            'page_blueprints' => self::pageBlueprints(),
        ];
    }
}
