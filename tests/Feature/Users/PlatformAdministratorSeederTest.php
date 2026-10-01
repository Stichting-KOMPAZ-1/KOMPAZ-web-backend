<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PlatformAdministratorSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_address_in_the_list_is_planted_as_an_invited_platform_administrator(): void
    {
        config()->set('kompaz.seed.platform_administrator_emails', ' One@Example.test, two@example.test ,,');

        $this->seed(DatabaseSeeder::class);

        $platform = Organization::query()->where('is_platform', true)->sole();
        $seeded = User::query()->orderBy('normalized_email')->get();

        $this->assertSame(['One@Example.test', 'two@example.test'], $seeded->pluck('email')->all());

        foreach ($seeded as $user) {
            $this->assertSame($platform->getKey(), $user->organization_id);
            $this->assertSame(UserRole::PlatformAdministrator, $user->role);
            $this->assertSame(UserStatus::Invited, $user->status);
        }
    }

    #[Test]
    public function running_it_again_plants_nobody_twice(): void
    {
        // It runs on every deploy, so a second pass has to be a no-op rather than a uniqueness
        // failure that stops the release.
        config()->set('kompaz.seed.platform_administrator_emails', 'one@example.test,two@example.test');

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(2, User::query()->count());
    }

    #[Test]
    public function an_address_somebody_already_holds_is_left_as_it_is(): void
    {
        // Adding a colleague's address to the list must not promote them, move them, or bring an
        // archived account back: a deploy is not the place to change anybody's access.
        $member = User::factory()->create(['email' => 'member@example.test', 'normalized_email' => 'member@example.test']);
        $archived = User::factory()->deleted()->create(['email' => 'gone@example.test', 'normalized_email' => 'gone@example.test']);

        config()->set('kompaz.seed.platform_administrator_emails', 'Member@example.test,gone@example.test,new@example.test');

        $this->seed(DatabaseSeeder::class);

        $member->refresh();
        $this->assertSame(UserRole::Member, $member->role);
        $this->assertNotSame(
            Organization::query()->where('is_platform', true)->sole()->getKey(),
            $member->organization_id,
        );
        $this->assertSoftDeleted($archived);
        $this->assertSame(1, User::query()->where('role', UserRole::PlatformAdministrator)->count());
    }

    #[Test]
    public function an_empty_list_plants_no_administrator(): void
    {
        config()->set('kompaz.seed.platform_administrator_emails', '');

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::query()->count());
        $this->assertSame(1, Organization::query()->where('is_platform', true)->count());
    }
}
