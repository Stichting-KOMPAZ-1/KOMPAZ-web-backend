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
 * Plants the organization that runs the platform, its first administrators, and the categories a
 * module is filed under.
 *
 * Both are idempotent, because this runs on every deploy. The platform organization is planted
 * here rather than created over the API: it is the only organization whose people may hold the
 * platform administrator role, and nothing over the wire sets that flag.
 *
 * The first administrator has to exist before anybody can be invited, because only an
 * administrator can invite. `SEED_PLATFORM_ADMINISTRATOR_EMAIL` names one address or several,
 * comma-separated. Each is seeded as `Invited` with no credential of their own and is sent
 * nothing — they ask for a sign-in link like everybody else, and redeeming it activates them. An
 * address that already has a row is left exactly as it is, deleted or not, in whichever
 * organization and role it holds: a deploy is not the place to promote anybody.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // The module categories are planted first and independently of everything below: nothing
        // creates one over the wire, so a deployment that never gets as far as an administrator
        // should still come up with a taxonomy to file modules under.
        $this->call(ModuleCategorySeeder::class);

        $organization = $this->platformOrganization();

        $emails = array_filter(
            array_map(trim(...), explode(',', (string) config('kompaz.seed.platform_administrator_emails'))),
            static fn (string $email): bool => $email !== '',
        );

        if ($emails === []) {
            $this->command->warn(
                'SEED_PLATFORM_ADMINISTRATOR_EMAIL is not set; no platform administrator was seeded.',
            );

            return;
        }

        foreach ($emails as $email) {
            $this->platformAdministrator($organization, $email);
        }
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
