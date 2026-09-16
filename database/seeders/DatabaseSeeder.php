<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Plants the organization that runs the platform, and its first administrator.
 *
 * Both are idempotent, because this runs on every deploy. The platform organization is planted
 * here rather than created over the API: it is the only organization whose people may hold the
 * platform administrator role, and nothing over the wire sets that flag.
 *
 * The first administrator has to exist before anybody can be invited, because only an
 * administrator can invite. They are seeded as `Invited` with no credential of their own — they
 * ask for a sign-in link like everybody else, and redeeming it activates them.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $organization = $this->platformOrganization();

        $email = (string) config('kompaz.seed.platform_administrator_email');

        if ($email === '') {
            $this->command->warn(
                'SEED_PLATFORM_ADMINISTRATOR_EMAIL is not set; no platform administrator was seeded.',
            );

            return;
        }

        $this->platformAdministrator($organization, $email);
    }

    private function platformOrganization(): Organization
    {
        $name = (string) config('kompaz.seed.platform_organization');

        $organization = Organization::query()->where('is_platform', true)->first();

        if ($organization !== null) {
            return $organization;
        }

        $organization = new Organization;
        $organization->applyName($name);
        $organization->is_platform = true;
        $organization->save();

        return $organization;
    }

    private function platformAdministrator(Organization $organization, string $email): void
    {
        $existing = User::withTrashed()
            ->where('normalized_email', User::normalize($email))
            ->first();

        if ($existing !== null) {
            return;
        }

        $user = new User;
        $user->organization_id = $organization->getKey();
        $user->email = trim($email);
        $user->normalized_email = User::normalize($email);
        $user->name = (string) config('kompaz.seed.platform_administrator_name');
        $user->role = UserRole::PlatformAdministrator;
        $user->status = UserStatus::Invited;
        $user->invited_at = Carbon::now();
        $user->save();
    }
}
