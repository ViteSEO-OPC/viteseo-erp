<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Services\ProfilePhotoService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Settings → Profile information → Change photo / Remove.
 *
 * Four things are load-bearing, each pinned from the direction it would actually fail:
 *
 *  - **Identity comes from the token.** A client-supplied user id must be inert. Asserted by
 *    sending one and checking the OTHER user's avatar is untouched — not by reading the controller.
 *  - **The bytes are private.** The file must land on the private disk, never `public`, because a
 *    profile photo must not be reachable by guessing a URL.
 *  - **Replacing discards the old file.** Otherwise every re-upload leaks a file forever.
 *  - **A foreign avatar value is never deleted from disk.** `users.avatar` is shared with the
 *    Projects module; the CRM may only unlink files it wrote itself.
 */
class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/crm/me/photo';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake(ProfilePhotoService::DISK);
    }

    private function actingAsUser(string $role = 'crm_admin'): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function png(string $name = 'avatar.png', int $kb = 100): UploadedFile
    {
        return UploadedFile::fake()->image($name, 200, 200)->size($kb);
    }

    public function test_it_uploads_a_photo_and_returns_a_usable_url(): void
    {
        $user = $this->actingAsUser();

        $response = $this->post(self::URI, ['photo' => $this->png()]);

        $response->assertOk();

        $path = $user->refresh()->avatar;

        $this->assertNotNull($path, 'The upload must persist a path on users.avatar.');
        $this->assertTrue(ProfilePhotoService::owns($path), 'The path must be module-owned.');
        Storage::disk(ProfilePhotoService::DISK)->assertExists($path);

        // The payload the UI actually renders.
        $response->assertJsonPath('data.avatar', $path);
        $this->assertNotNull($response->json('data.avatar_url'));
    }

    public function test_the_photo_survives_a_refetch(): void
    {
        $this->actingAsUser();

        $this->post(self::URI, ['photo' => $this->png()])->assertOk();

        // The whole point of the feature: it is still there on the next request, which is what a
        // page refresh or a fresh login performs.
        $this->getJson('/api/v1/crm/me')
            ->assertOk()
            ->assertJsonPath('data.avatar_url', fn ($url) => is_string($url) && $url !== '');
    }

    public function test_replacing_a_photo_deletes_the_previous_file(): void
    {
        $user = $this->actingAsUser();

        $this->post(self::URI, ['photo' => $this->png('first.png')])->assertOk();
        $first = $user->refresh()->avatar;

        $this->post(self::URI, ['photo' => $this->png('second.png')])->assertOk();
        $second = $user->refresh()->avatar;

        $this->assertNotSame($first, $second);
        Storage::disk(ProfilePhotoService::DISK)->assertMissing($first);
        Storage::disk(ProfilePhotoService::DISK)->assertExists($second);
    }

    public function test_removing_a_photo_clears_the_row_and_the_file(): void
    {
        $user = $this->actingAsUser();

        $this->post(self::URI, ['photo' => $this->png()])->assertOk();
        $path = $user->refresh()->avatar;

        $this->deleteJson(self::URI)
            ->assertOk()
            ->assertJsonPath('data.avatar', null)
            ->assertJsonPath('data.avatar_url', null);

        $this->assertNull($user->refresh()->avatar);
        Storage::disk(ProfilePhotoService::DISK)->assertMissing($path);
    }

    public function test_removing_without_a_photo_is_idempotent(): void
    {
        $this->actingAsUser();

        $this->deleteJson(self::URI)->assertOk()->assertJsonPath('data.avatar', null);
        $this->deleteJson(self::URI)->assertOk()->assertJsonPath('data.avatar', null);
    }

    /**
     * `users.avatar` is shared with Projects, whose column comment says "URL/path". A value this
     * module did not write must be surfaced unchanged and, above all, must never be deleted from
     * disk on a guess.
     */
    public function test_a_foreign_avatar_value_is_respected_and_never_unlinked(): void
    {
        $user = $this->actingAsUser();
        $user->forceFill(['avatar' => 'https://cdn.example.test/someone.png'])->save();

        $this->getJson('/api/v1/crm/me')
            ->assertOk()
            ->assertJsonPath('data.avatar_url', 'https://cdn.example.test/someone.png');

        // Removing clears the row but must not attempt a disk delete for a path it does not own.
        $this->deleteJson(self::URI)->assertOk();
        $this->assertNull($user->refresh()->avatar);
        $this->assertFalse(ProfilePhotoService::owns('https://cdn.example.test/someone.png'));
    }

    public function test_it_rejects_a_non_image_and_an_oversized_file(): void
    {
        $this->actingAsUser();

        $this->postJson(self::URI, ['photo' => UploadedFile::fake()->create('resume.pdf', 10, 'application/pdf')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');

        // NOTE — the "renamed executable" case is deliberately NOT asserted here. `image` rejects it
        // in production because Laravel derives the mime from the file's CONTENT (finfo), but
        // `UploadedFile::fake()->create($name, $size, $mime)` fakes the reported mime outright, so
        // such a test would only prove that the fake lied convincingly. Asserting it would be
        // theatre. The rule is exercised for real by the honest-mime cases above and below.

        // 4 MB is the documented cap ("PNG or JPG, up to 4 MB.").
        $this->postJson(self::URI, ['photo' => $this->png('huge.png', 5000)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');

        // WebP is a real image type, but outside what the UI promises — so `mimes` must narrow it
        // away even though `image` would accept it. (Built with create() rather than image(): the
        // fake image generator needs GD's imagewebp, which is not built into every PHP image.)
        $this->postJson(self::URI, ['photo' => UploadedFile::fake()->create('a.webp', 10, 'image/webp')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');
    }

    public function test_a_client_supplied_user_id_cannot_target_another_account(): void
    {
        $victim = User::factory()->create(['status' => 'active']);
        $attacker = $this->actingAsUser('crm_bd');

        $this->post(self::URI, [
            'photo' => $this->png(),
            'user_id' => $victim->id,
            'id' => $victim->id,
        ])->assertOk();

        // The caller got the photo; the named victim did not.
        $this->assertNotNull($attacker->refresh()->avatar);
        $this->assertNull($victim->refresh()->avatar);
    }

    /**
     * The one claim `Storage::fake()` cannot make: that the issued link actually SERVES the bytes.
     *
     * Everything else here runs against a faked disk, which happily returns a URL for a route that
     * might not exist. This test uses the REAL private disk and follows the link the API handed
     * out, because a signed URL that 404s would look identical in every other assertion — and would
     * mean an avatar that uploads successfully and then never renders.
     */
    public function test_the_issued_link_actually_serves_the_image(): void
    {
        // Undo the setUp() fake for this test only — a faked disk does not inherit the real disk's
        // `serve => true`, which is precisely the setting under test here.
        Storage::clearResolvedInstances();
        $this->app->forgetInstance('filesystem');

        $user = $this->actingAsUser();

        try {
            $this->post(self::URI, ['photo' => $this->png()])->assertOk();

            $url = $this->getJson('/api/v1/crm/me')->json('data.avatar_url');
            $this->assertIsString($url);

            // Follow the path + signature, not the absolute host: the test app's URL need not match
            // whatever APP_URL the container is configured with.
            $target = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

            $this->get($target)
                ->assertOk()
                ->assertHeader('Content-Type', 'image/png');

            // And the signature is load-bearing — stripped, the file must not be served.
            $this->get((string) parse_url($url, PHP_URL_PATH))->assertForbidden();
        } finally {
            Storage::disk(ProfilePhotoService::DISK)->deleteDirectory(
                ProfilePhotoService::DIRECTORY.'/'.$user->getKey()
            );
        }
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson(self::URI, ['photo' => $this->png()])->assertUnauthorized();
        $this->deleteJson(self::URI)->assertUnauthorized();
    }
}
