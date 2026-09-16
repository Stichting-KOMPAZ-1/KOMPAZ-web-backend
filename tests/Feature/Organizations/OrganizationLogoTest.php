<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Models\OrganizationLogo;
use App\Models\User;
use App\Support\Images\LogoImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
