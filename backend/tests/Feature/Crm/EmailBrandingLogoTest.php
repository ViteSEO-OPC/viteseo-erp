<?php

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Database\Seeders\EmailTemplateSeeder;
use App\Modules\Crm\Models\EmailAsset;
use App\Modules\Crm\Models\EmailBranding;
use App\Modules\Crm\Models\EmailDesign;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Services\Outreach\EmailAssetService;
use App\Modules\Crm\Support\EmailAssets\LogoPng;
use App\Modules\Crm\Support\EmailAssets\SvgSanitizer;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Unit\Crm\EmailDesign\BuildsEmailDesigns;

/**
 * The organization logo, end to end: uploading and cropping a PNG or an SVG, the public route email clients fetch
 * it from, and — the architecture's point — every design and template showing whatever logo the branding points at
 * now, with no design ever storing the image or its address.
 */
class EmailBrandingLogoTest extends TestCase
{
    use BuildsEmailDesigns;
    use RefreshDatabase;

    private const URL = '/api/v1/crm/settings/email-branding';

    private const DESIGNS_URL = '/api/v1/crm/settings/email-designs';

    private const TEMPLATES_URL = '/api/v1/crm/settings/email-templates';

    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 100"><script>alert(1)</script>'
        .'<rect width="200" height="100" fill="#0d9488" onclick="alert(1)"/><image href="https://evil.example/t.png" width="1" height="1"/></svg>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(EmailTemplateSeeder::class);
        Storage::fake(EmailAssetService::DISK);
    }

    private function actingAsRole(string $role = 'crm_admin'): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    /** A transparent PNG whose right half is one opaque color. */
    private function png(int $width, int $height, array $rgb = [220, 38, 38]): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, imagecolorallocatealpha($image, $rgb[0], $rgb[1], $rgb[2], 0));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function crop(float $x, float $y, float $width, float $height): string
    {
        return (string) json_encode(['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height]);
    }

    /** @param  array<string, mixed>  $fields */
    private function upload(array $fields): TestResponse
    {
        return $this->post(self::URL.'/logo', $fields, ['Accept' => 'application/json']);
    }

    private function uploadPng(string $bytes, ?string $crop = null): TestResponse
    {
        [$width, $height] = getimagesizefromstring($bytes);

        return $this->upload([
            'file' => UploadedFile::fake()->createWithContent('logo.png', $bytes),
            'crop' => $crop ?? $this->crop(0, 0, $width, $height),
        ]);
    }

    private function pathOf(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }

    private function designPreview(): string
    {
        return (string) $this->postJson(self::DESIGNS_URL.'/preview', ['layout' => json_encode($this->layout([$this->logo()], []))])
            ->assertOk()->json('data.html');
    }

    private function inline(string $png): string
    {
        return 'src="data:image/png;base64,'.base64_encode($png).'"';
    }

    /** @return array{0: Org, 1: User} */
    private function orgWithAdmin(string $slug): array
    {
        $org = app(OrgOnboardingService::class)->createOrg(
            "Org {$slug}", "Owner {$slug}", "owner-{$slug}@client.ph", 'password-123', ['crm' => 'full'],
        )['org'];

        $admin = User::factory()->orgless()->create(['status' => 'active', 'name' => "Admin {$slug}"]);
        $admin->assignRole('crm_admin');
        $org->users()->attach($admin->id, ['is_default' => true]);

        return [$org, $admin];
    }

    public function test_only_management_roles_manage_the_logo(): void
    {
        $this->actingAsRole('crm_bd');

        $this->getJson(self::URL)->assertForbidden();
        $this->uploadPng($this->png(40, 20))->assertForbidden();
        $this->deleteJson(self::URL.'/logo')->assertForbidden();
        $this->assertSame(0, EmailAsset::query()->count());
    }

    public function test_an_organization_starts_with_no_logo_and_reading_writes_nothing(): void
    {
        $this->actingAsRole();

        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.logo', null);
        $this->assertSame(0, EmailBranding::query()->count());
    }

    public function test_a_png_logo_is_cropped_re_encoded_and_served_publicly(): void
    {
        $this->actingAsRole();
        $original = $this->png(800, 400);

        $response = $this->uploadPng($original, $this->crop(400, 0, 400, 400))
            ->assertCreated()
            ->assertJsonPath('data.logo.sourceFormat', 'png')
            ->assertJsonPath('data.logo.width', 400)
            ->assertJsonPath('data.logo.height', 400);

        $asset = EmailAsset::query()->sole();
        $this->assertNull($asset->svg_path);
        $this->assertSame(EmailBranding::query()->sole()->logo_asset_id, $asset->id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $asset->uuid);

        $stored = (string) Storage::disk(EmailAssetService::DISK)->get($asset->path);
        $this->assertNotSame($original, $stored);
        $this->assertSame([400, 400], array_slice(getimagesizefromstring($stored), 0, 2));
        $this->assertSame(hash('sha256', $stored), $asset->sha256);

        $path = $this->pathOf((string) $response->json('data.logo.url'));
        $this->assertSame('/api/v1/crm/email-assets/'.$asset->uuid.'.png', $path);

        $served = $this->get($path)->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('immutable', (string) $served->headers->get('Cache-Control'));
        $this->assertSame($stored, $served->getContent());

        $route = app('router')->getRoutes()->match(Request::create($path));
        $this->assertNotContains('auth:sanctum', $route->gatherMiddleware(), 'email clients cannot authenticate');
    }

    public function test_the_public_route_serves_only_a_known_assets_png(): void
    {
        $this->actingAsRole();
        $this->uploadPng($this->png(40, 20))->assertCreated();
        $asset = EmailAsset::query()->sole();

        $this->get('/api/v1/crm/email-assets/'.Str::uuid()->toString().'.png')->assertNotFound();
        $this->get('/api/v1/crm/email-assets/'.strtoupper($asset->uuid).'.png')->assertNotFound();
        $this->get('/api/v1/crm/email-assets/'.$asset->uuid.'.svg')->assertNotFound();
        $this->get('/api/v1/crm/email-assets/not-a-uuid.png')->assertNotFound();

        $asset->delete();
        $this->get('/api/v1/crm/email-assets/'.$asset->uuid.'.png')->assertNotFound();
    }

    public function test_an_svg_logo_is_sanitized_cropped_as_vector_and_given_a_png_for_email(): void
    {
        $this->actingAsRole();
        $file = fn () => UploadedFile::fake()->createWithContent('logo.svg', self::SVG);

        $inspection = $this->post(self::URL.'/logo/inspect', ['file' => $file()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.format', 'svg')
            ->assertJsonPath('data.width', 200)
            ->assertJsonPath('data.height', 100);
        $this->assertStringNotContainsString('script', (string) $inspection->json('data.svg'));
        $this->assertStringNotContainsString('onclick', (string) $inspection->json('data.svg'));
        $this->assertStringNotContainsString('evil.example', (string) $inspection->json('data.svg'));
        $this->assertSame(['event handler' => 1, 'image' => 1, 'script' => 1], $inspection->json('data.removed'));
        $this->assertSame(0, EmailAsset::query()->count(), 'inspecting stores nothing');

        $this->upload(['file' => $file(), 'crop' => $this->crop(100, 0, 100, 100)])
            ->assertUnprocessable()->assertJsonValidationErrors(['rendition']);

        $this->upload([
            'file' => $file(),
            'crop' => $this->crop(100, 0, 100, 100),
            'rendition' => UploadedFile::fake()->createWithContent('rendition.png', $this->png(600, 200)),
        ])->assertUnprocessable()->assertJsonPath('errors.rendition.0', LogoPng::RENDITION_MISMATCH);

        $this->upload([
            'file' => $file(),
            'crop' => $this->crop(100, 0, 100, 100),
            'rendition' => UploadedFile::fake()->createWithContent('rendition.png', $this->png(600, 600)),
        ])->assertCreated()
            ->assertJsonPath('data.logo.sourceFormat', 'svg')
            ->assertJsonPath('data.logo.width', 600)
            ->assertJsonPath('data.logo.height', 600);

        $asset = EmailAsset::query()->sole();
        $svg = (string) Storage::disk(EmailAssetService::DISK)->get((string) $asset->svg_path);
        $this->assertStringContainsString('viewBox="100 0 100 100"', $svg);
        $this->assertStringContainsString('<rect width="200" height="100" fill="#0d9488"/>', $svg);
        $this->assertStringNotContainsString('script', $svg);
        $this->assertSame([600, 600], array_slice(getimagesizefromstring((string) Storage::disk(EmailAssetService::DISK)->get($asset->path)), 0, 2));
        $this->assertSame(['event handler' => 1, 'image' => 1, 'script' => 1], $asset->meta['removed']);
    }

    public function test_uploads_that_are_not_a_safe_png_or_svg_are_refused_and_nothing_is_stored(): void
    {
        $this->actingAsRole();
        $jpeg = (function (): string {
            ob_start();
            imagejpeg(imagecreatetruecolor(20, 20));

            return (string) ob_get_clean();
        })();

        $this->upload(['file' => UploadedFile::fake()->createWithContent('logo.png', $jpeg), 'crop' => $this->crop(0, 0, 20, 20)])
            ->assertUnprocessable()->assertJsonPath('errors.file.0', EmailAssetService::UNSUPPORTED);
        $this->upload(['file' => UploadedFile::fake()->createWithContent('logo.svg', 'hello'), 'crop' => $this->crop(0, 0, 20, 20)])
            ->assertUnprocessable()->assertJsonPath('errors.file.0', EmailAssetService::UNSUPPORTED);
        $this->upload(['file' => UploadedFile::fake()->createWithContent('logo.svg', '<!DOCTYPE svg><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><rect width="1" height="1"/></svg>'), 'crop' => $this->crop(0, 0, 1, 1)])
            ->assertUnprocessable()->assertJsonPath('errors.file.0', SvgSanitizer::HAS_DOCTYPE);
        $this->upload(['file' => UploadedFile::fake()->createWithContent('logo.gif', $this->png(20, 20)), 'crop' => $this->crop(0, 0, 20, 20)])
            ->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->uploadPng($this->png(40, 20), 'not json')->assertUnprocessable()->assertJsonPath('errors.crop.0', EmailAssetService::CROP_INVALID);
        $this->uploadPng($this->png(40, 20), $this->crop(30, 0, 20, 20))->assertUnprocessable()->assertJsonPath('errors.file.0', LogoPng::CROP_OUTSIDE);

        $this->assertSame(0, EmailAsset::query()->count());
        $this->assertSame([], Storage::disk(EmailAssetService::DISK)->allFiles());
    }

    public function test_every_design_and_template_previews_the_current_logo_and_no_design_stores_it(): void
    {
        $this->actingAsRole();
        $plainBefore = EmailTemplate::query()->whereNull('content')->orderBy('id')->get(['id', 'body', 'updated_at'])->toArray();

        $this->uploadPng($this->png(80, 40, [220, 38, 38]))->assertCreated();
        $red = EmailAsset::query()->sole();
        $redPng = (string) Storage::disk(EmailAssetService::DISK)->get($red->path);

        $designId = $this->postJson(self::DESIGNS_URL, ['name' => 'Shared', 'layout' => json_encode($this->layout([$this->logo()], []))])
            ->assertCreated()->json('data.id');
        $templatePreview = fn (): string => (string) $this->postJson(self::TEMPLATES_URL.'/preview', [
            'content' => json_encode($this->content()), 'subject' => 'Hello', 'email_design_id' => $designId,
        ])->assertOk()->json('data.html');
        $defaultPreview = fn (): string => (string) $this->postJson(self::TEMPLATES_URL.'/preview', [
            'content' => json_encode($this->content()), 'subject' => 'Hello',
        ])->assertOk()->json('data.html');

        $this->assertStringContainsString($this->inline($redPng), $this->designPreview());
        $this->assertStringContainsString($this->inline($redPng), $templatePreview());
        $this->assertStringContainsString($this->inline($redPng), $defaultPreview());

        $this->uploadPng($this->png(80, 40, [37, 99, 235]))->assertCreated();
        $blue = EmailAsset::query()->whereKeyNot($red->id)->sole();
        $bluePng = (string) Storage::disk(EmailAssetService::DISK)->get($blue->path);

        foreach ([$this->designPreview(), $templatePreview(), $defaultPreview()] as $html) {
            $this->assertStringContainsString($this->inline($bluePng), $html);
            $this->assertStringNotContainsString($this->inline($redPng), $html);
        }

        // The replaced asset is kept and still served, so an email that was already sent keeps its logo.
        $this->get('/api/v1/crm/email-assets/'.$red->uuid.'.png')->assertOk();

        foreach (EmailDesign::withoutGlobalScopes()->get() as $design) {
            $layout = (string) json_encode($design->layout);
            $this->assertStringNotContainsString('email-assets', $layout);
            $this->assertStringNotContainsString($red->uuid, $layout);
            $this->assertStringNotContainsString($blue->uuid, $layout);
            $this->assertStringNotContainsString('data:image', $layout);
        }

        $this->assertSame($plainBefore, EmailTemplate::query()->whereNull('content')->orderBy('id')->get(['id', 'body', 'updated_at'])->toArray());
    }

    public function test_removing_the_logo_leaves_it_out_of_previews(): void
    {
        $this->actingAsRole();
        $this->uploadPng($this->png(80, 40))->assertCreated();
        $this->assertStringContainsString('<img', $this->designPreview());

        $this->deleteJson(self::URL.'/logo')->assertOk()->assertJsonPath('data.logo', null);

        $this->assertStringNotContainsString('<img', $this->designPreview());
        $this->assertSame(1, EmailAsset::query()->count(), 'the asset is kept for emails already sent');
    }

    public function test_branding_belongs_to_one_organization(): void
    {
        [, $theirAdmin] = $this->orgWithAdmin('theirs');
        [, $ourAdmin] = $this->orgWithAdmin('ours');

        CurrentOrg::clear();
        Sanctum::actingAs($theirAdmin);
        $this->uploadPng($this->png(80, 40))->assertCreated();
        $this->assertStringContainsString('<img', $this->designPreview());

        CurrentOrg::clear();
        Sanctum::actingAs($ourAdmin);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.logo', null);
        $this->assertStringNotContainsString('<img', $this->designPreview());
        $this->deleteJson(self::URL.'/logo')->assertOk();

        $this->assertSame(1, EmailBranding::withoutGlobalScopes()->whereNotNull('logo_asset_id')->count());
    }
}
