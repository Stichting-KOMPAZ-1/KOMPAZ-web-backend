<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Actions\Modules\SyncModuleActivationsAction;
use App\Enums\UserRole;
use App\Events\ContentFileDiscarded;
use App\Exceptions\ForbiddenAccessException;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\ModuleContact;
use App\Models\ModuleVideo;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Assigning a module to organizations — "Actief bij".
 *
 * The rule under test is the one that made this an action rather than a form field: an
 * organization that already had the module keeps the date it got it. Their table is ordered by
 * that date so an unfilled module floats to the top, and a plain sync would reset every one of
 * them each time a platform administrator saved the form for an unrelated reason.
 */
final class AssignModuleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function assigning_a_module_puts_it_in_front_of_the_chosen_organizations(): void
    {
        $module = Module::factory()->create();
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();

        $this->sync($module, [$first, $second]);

        $this->assertDatabaseCount('module_activations', 2);
        $this->assertNotNull($module->activationFor((string) $first->getKey()));
        $this->assertNotNull($module->activationFor((string) $second->getKey()));
    }

    #[Test]
    public function an_organization_that_already_had_it_keeps_the_date_it_got_it(): void
    {
        // The whole reason this is a use case and not a form field.
        $module = Module::factory()->create();
        $existing = Organization::factory()->create();
        $newcomer = Organization::factory()->create();

        $yesterday = Carbon::now()->subDay();

        $original = ModuleActivation::factory()
            ->ofModule($module)->forOrganization($existing)
            ->create(['activated_at' => $yesterday]);

        $this->sync($module, [$existing, $newcomer]);

        $kept = $module->activationFor((string) $existing->getKey());

        $this->assertNotNull($kept);

        // The same row, not an identical-looking replacement. This is the strongest form of the
        // claim: a sync() would have detached and re-attached, giving it a new key and today's
        // date, and the organization's videos and contacts would have gone with the old one.
        $this->assertSame($original->getKey(), $kept->getKey());

        // To the second, because Eloquent writes timestamps at second precision by default even
        // though the column holds six digits. Ordering a table by it does not care.
        $this->assertSame(
            $yesterday->format('Y-m-d H:i:s'),
            $kept->activated_at->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function an_organization_left_out_loses_it(): void
    {
        $module = Module::factory()->create();
        $staying = Organization::factory()->create();
        $going = Organization::factory()->create();

        $this->sync($module, [$staying, $going]);
        $this->sync($module, [$staying]);

        $this->assertNotNull($module->activationFor((string) $staying->getKey()));
        $this->assertNull($module->activationFor((string) $going->getKey()));
    }

    #[Test]
    public function taking_a_module_away_takes_that_organizations_own_additions_with_it(): void
    {
        // Their videos, links and contact card were about a module they no longer have. Keeping
        // them would resurrect somebody's old phone number the day the module came back.
        $module = Module::factory()->create();
        $organization = Organization::factory()->create();

        $this->sync($module, [$organization]);

        $activation = $module->activationFor((string) $organization->getKey());
        $this->assertNotNull($activation);

        ModuleContact::factory()->ofActivation($activation)->create();
        ModuleVideo::factory()->ofActivation($activation)->create();

        $this->sync($module, []);

        $this->assertDatabaseCount('module_activations', 0);
        $this->assertDatabaseCount('module_contacts', 0);
        $this->assertDatabaseCount('module_videos', 0);
    }

    #[Test]
    public function the_files_under_a_withdrawn_activation_are_discarded(): void
    {
        // The cascade takes those video rows without Eloquent seeing one of them, so the keys have
        // to be collected before the delete or nothing ever knows where the bytes were.
        Event::fake([ContentFileDiscarded::class]);

        $module = Module::factory()->create();
        $organization = Organization::factory()->create();

        $this->sync($module, [$organization]);

        $activation = $module->activationFor((string) $organization->getKey());
        $this->assertNotNull($activation);

        $video = ModuleVideo::factory()->ofActivation($activation)->uploaded()->create();

        $this->sync($module, []);

        Event::assertDispatched(
            ContentFileDiscarded::class,
            fn (ContentFileDiscarded $event): bool => $event->storageKey === $video->file_storage_key,
        );
    }

    #[Test]
    public function an_identifier_for_an_organization_that_no_longer_exists_is_dropped(): void
    {
        // A form somebody left open is the ordinary way this happens; a row naming a tenant that
        // is gone would be the alternative.
        $module = Module::factory()->create();
        $real = Organization::factory()->create();

        $this->sync($module, [], ['00000000-0000-7000-8000-000000000000', (string) $real->getKey()]);

        $this->assertDatabaseCount('module_activations', 1);
        $this->assertNotNull($module->activationFor((string) $real->getKey()));
    }

    #[Test]
    public function an_organization_administrator_cannot_decide_who_gets_a_module(): void
    {
        // They add their own material to a module; what the module says and who else has it is the
        // platform's call.
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $module = Module::factory()->create();

        $this->expectException(ForbiddenAccessException::class);

        app(SyncModuleActivationsAction::class)
            ->execute($administrator, $module, [(string) $administrator->organization_id]);
    }

    /**
     * @param  list<Organization>  $organizations
     * @param  list<string>  $extraIds
     */
    private function sync(Module $module, array $organizations, array $extraIds = []): void
    {
        $ids = array_map(static fn (Organization $o): string => (string) $o->getKey(), $organizations);

        app(SyncModuleActivationsAction::class)->execute(
            $this->platformAdministrator(),
            $module,
            array_merge($ids, $extraIds),
        );
    }

    private ?User $operator = null;

    /**
     * Cached on the instance, not statically: PHPUnit builds a fresh test object per test and
     * `RefreshDatabase` empties the tables between them, so a static would hand the second test a
     * user whose row no longer exists.
     */
    private function platformAdministrator(): User
    {
        return $this->operator ??= User::factory()->create([
            'organization_id' => Organization::factory()->platform()->create()->getKey(),
            'role' => UserRole::PlatformAdministrator,
        ]);
    }
}
