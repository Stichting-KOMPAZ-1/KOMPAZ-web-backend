<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Models\OrganizationLogo;
use App\Models\User;
use App\Support\Images\LogoImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OrganizationLogoTest extends TestCase
{
    use RefreshDatabase;

    private const string PNG = "\x89PNG\r\n\x1a\n".'the rest does not matter';

    #[Test]
    public function an_organization_without_a_logo_is_served_the_placeholder(): void
    {
        $member = User::factory()->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->get("/api/organizations/{$member->organization_id}/logo")
            ->assertOk()
            ->assertHeader('Content-Type', LogoImage::SVG)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    }

    #[Test]
    public function the_signed_in_user_is_given_their_organizations_logo_for_the_header(): void
    {
        // KOM-112. Null until there is a logo, so the header shows its own rather than the
        // placeholder the address would answer with.
        $admin = User::factory()->administrator()->create();
        $member = User::factory()->for($admin->organization)->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('organizationLogoUrl', null);

        $this->withHeaders($this->tokenHeaders($admin))
            ->put("/api/organizations/{$admin->organization_id}/logo", [
                'logo' => UploadedFile::fake()->createWithContent('logo.png', self::PNG),
            ])
            ->assertOk();

        $logoUrl = $this->withHeaders($this->tokenHeaders($member))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('organizationLogoUrl', "/api/organizations/{$member->organization_id}/logo")
            ->json('organizationLogoUrl');

        $this->assertIsString($logoUrl);

        $this->withHeaders($this->tokenHeaders($member))
            ->get($logoUrl)
            ->assertOk()
            ->assertHeader('Content-Type', LogoImage::PNG);
    }

    /**
     * The header draws the logo with an `<img>`, which sends the session cookie and a Referer but
     * no Origin and no token. Whether the cookie is read at all is decided by Sanctum matching that
     * Referer against `sanctum.stateful`, so that decision is what is asserted: a request made
     * through this harness would not show it, because `TestCase::call()` puts the browser's user
     * back on every request whatever headers it carries.
     */
    #[Test]
    public function an_img_on_the_frontend_is_read_as_the_browser_application_and_gets_its_cookie(): void
    {
        $frontend = rtrim((string) config('kompaz.frontend_url'), '/');
        $logo = '/api/organizations/'.User::factory()->create()->organization_id.'/logo';

        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend(
            Request::create($logo, 'GET', server: ['HTTP_REFERER' => $frontend.'/modules']),
        ));

        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend(
            Request::create($logo, 'GET', server: ['HTTP_REFERER' => 'https://elders.example/modules']),
        ));
    }

    #[Test]
    public function an_administrator_uploads_a_logo(): void
    {
        $admin = User::factory()->administrator()->create();

        $this->withHeaders($this->tokenHeaders($admin))
            ->put("/api/organizations/{$admin->organization_id}/logo", [
                'logo' => UploadedFile::fake()->createWithContent('logo.png', self::PNG),
            ])
            ->assertOk()
            ->assertJsonPath('hasLogo', true);

        $logo = OrganizationLogo::query()->sole();
        $this->assertSame(LogoImage::PNG, $logo->content_type);
        Storage::disk()->assertExists($logo->storage_key);
    }

    #[Test]
    public function the_stored_media_type_comes_from_the_bytes_not_from_the_upload(): void
    {
        $admin = User::factory()->administrator()->create();

        // A PNG that claims to be an SVG. What the row records is what a later response is
        // labelled with, so the claim must not be believed.
        $this->withHeaders($this->tokenHeaders($admin))
            ->put("/api/organizations/{$admin->organization_id}/logo", [
                'logo' => UploadedFile::fake()->createWithContent('logo.svg', self::PNG),
            ])
            ->assertOk();

        $this->assertSame(LogoImage::PNG, OrganizationLogo::query()->sole()->content_type);
    }

    #[Test]
    public function a_file_that_is_not_an_accepted_image_is_refused(): void
    {
        $admin = User::factory()->administrator()->create();

        $this->withHeaders($this->tokenHeaders($admin))
            ->put("/api/organizations/{$admin->organization_id}/logo", [
                'logo' => UploadedFile::fake()->createWithContent('payload.png', '<html><svg></svg></html>'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(400)
            ->assertJsonPath('errors.logo.0', 'Upload een afbeelding van het type '.LogoImage::ACCEPTED_FORMATS.'.');

        $this->assertSame(0, OrganizationLogo::query()->count());
    }

    #[Test]
    public function a_file_over_the_limit_is_refused(): void
    {
        $admin = User::factory()->administrator()->create();
        $tooBig = str_pad(self::PNG, LogoImage::maximumSizeInBytes() + 1, 'x');

        $this->withHeaders($this->tokenHeaders($admin))
            ->put("/api/organizations/{$admin->organization_id}/logo", [
                'logo' => UploadedFile::fake()->createWithContent('logo.png', $tooBig),
            ], ['Accept' => 'application/json'])
            ->assertStatus(400)
            ->assertJsonPath('errors.logo.0', 'Upload een kleiner bestand van maximaal '.LogoImage::MAXIMUM_SIZE.'.');
    }

    #[Test]
    public function replacing_a_logo_removes_the_file_it_stops_pointing_at(): void
    {
        $admin = User::factory()->administrator()->create();
        $headers = $this->tokenHeaders($admin);

        $this->withHeaders($headers)->put("/api/organizations/{$admin->organization_id}/logo", [
            'logo' => UploadedFile::fake()->createWithContent('first.png', self::PNG),
        ])->assertOk();

        $first = OrganizationLogo::query()->sole()->storage_key;

        $this->withHeaders($headers)->put("/api/organizations/{$admin->organization_id}/logo", [
            'logo' => UploadedFile::fake()->createWithContent('second.png', self::PNG.'second'),
        ])->assertOk();

        $second = OrganizationLogo::query()->sole()->storage_key;

        $this->assertNotSame($first, $second, 'A replacement writes a new key rather than overwriting the old.');
        Storage::disk()->assertMissing($first);
        Storage::disk()->assertExists($second);
    }

    #[Test]
    public function deleting_a_logo_puts_the_placeholder_back(): void
    {
        $admin = User::factory()->administrator()->create();
        $headers = $this->tokenHeaders($admin);

        $this->withHeaders($headers)->put("/api/organizations/{$admin->organization_id}/logo", [
            'logo' => UploadedFile::fake()->createWithContent('logo.png', self::PNG),
        ])->assertOk();

        $key = OrganizationLogo::query()->sole()->storage_key;

        $this->withHeaders($headers)
            ->deleteJson("/api/organizations/{$admin->organization_id}/logo")
            ->assertNoContent();

        Storage::disk()->assertMissing($key);

        $this->withHeaders($headers)
            ->get("/api/organizations/{$admin->organization_id}/logo")
            ->assertOk()
            ->assertHeader('Content-Type', LogoImage::SVG);
    }

    #[Test]
    public function a_row_naming_a_missing_file_falls_back_to_the_placeholder(): void
    {
        $admin = User::factory()->administrator()->create();
        $headers = $this->tokenHeaders($admin);

        $this->withHeaders($headers)->put("/api/organizations/{$admin->organization_id}/logo", [
            'logo' => UploadedFile::fake()->createWithContent('logo.png', self::PNG),
        ])->assertOk();

        // The file is gone but the row still names it — one lost file must not become a page that
        // will not render.
        Storage::disk()->delete(OrganizationLogo::query()->sole()->storage_key);

        $this->withHeaders($headers)
            ->get("/api/organizations/{$admin->organization_id}/logo")
            ->assertOk()
            ->assertHeader('Content-Type', LogoImage::SVG);
    }

    #[Test]
    public function a_member_cannot_upload_a_logo(): void
    {
        $member = User::factory()->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->put("/api/organizations/{$member->organization_id}/logo", [
                'logo' => UploadedFile::fake()->createWithContent('logo.png', self::PNG),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    #[Test]
    public function reading_another_organizations_logo_is_refused(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->get("/api/organizations/{$other->organization_id}/logo")
            ->assertForbidden();
    }
}
