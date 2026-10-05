<?php

declare(strict_types=1);

namespace Tests\Feature\Nova;

use App\Enums\LoginTokenPurpose;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\LoginToken;
use App\Models\Organization;
use App\Models\User;
use App\Nova\Actions\InviteUser;
use App\Services\SecretTokenFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * What an organization administrator sees in the panel.
 *
 * The panel was platform administrators only until they were let in, so every listing in it was
 * written when there was nobody to hide anything from. These are the tests for the other half of
 * that change: not that an organization administrator can get in, but that getting in shows them
 * their own tenant and no one else's. An unscoped listing here is a tenant leak rather than an
 * untidy page, which is why each one is asserted separately instead of trusting a shared helper.
 */
final class NovaTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_organization_administrator_can_sign_in_to_the_panel(): void
    {
        $admin = $this->signedInAdministrator();

        $this->assertAuthenticatedAs($admin->fresh(), 'web');
    }

    #[Test]
    public function the_roster_is_their_own_organization_only(): void
    {
        $admin = $this->signedInAdministrator();
        $colleague = User::factory()->for($admin->organization)->create();
        $stranger = User::factory()->for(Organization::factory())->create();

        $listed = $this->listedIdentifiers('/nova-api/users');

        $this->assertContains((string) $admin->getKey(), $listed);
        $this->assertContains((string) $colleague->getKey(), $listed);
        $this->assertNotContains((string) $stranger->getKey(), $listed);
    }

    #[Test]
    public function the_organizations_list_is_their_own_only(): void
    {
        $admin = $this->signedInAdministrator();
        $other = Organization::factory()->create();

        $listed = $this->listedIdentifiers('/nova-api/organizations');

        $this->assertSame([(string) $admin->organization_id], $listed);
        $this->assertNotContains((string) $other->getKey(), $listed);
    }

    /**
     * A detail page is reached by typing a key as readily as by clicking a row, so a boundary
     * stated only on the index is not stated at all.
     */
    #[Test]
    public function somebody_else_s_tenant_cannot_be_opened_by_its_key(): void
    {
        $this->signedInAdministrator();
        $stranger = User::factory()->for(Organization::factory())->create();

        $this->getJson('/nova-api/users/'.$stranger->getKey())
            ->assertStatus(Response::HTTP_NOT_FOUND);

        $this->getJson('/nova-api/organizations/'.$stranger->organization_id)
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }

    /**
     * Creating, archiving and deleting a tenant belong to the platform. The use cases refuse an
     * organization administrator anyway; not offering the buttons is what stops the panel promising
     * something it will then answer with a banner.
     */
    #[Test]
    public function the_platforms_own_operations_are_not_offered(): void
    {
        $admin = $this->signedInAdministrator();

        $offered = $this->offeredActions('organizations', (string) $admin->organization_id);

        $this->assertNotContains('organisatie-aanmaken', $offered);
        $this->assertNotContains('organisatie-archiveren', $offered);
        $this->assertNotContains('organisatie-verwijderen', $offered);

        // What the API lets an administrator do for their own organization stays.
        $this->assertContains('organisatie-wijzigen', $offered);
        $this->assertContains('logo-uploaden', $offered);
    }

    /** The picker an invite opens names only the organizations the operator may invite into. */
    #[Test]
    public function the_organization_picker_names_no_other_tenant(): void
    {
        $admin = $this->signedInAdministrator();
        $other = Organization::factory()->create();

        $options = \App\Nova\Organization::options();

        $this->assertSame([(string) $admin->organization_id], array_keys($options));
        $this->assertArrayNotHasKey((string) $other->getKey(), $options);
    }

    /**
     * The invite form's organization picker is not narrowed for an organization administrator, it
     * is absent.
     *
     * The one organization they could pick is the one an empty picker already means, so the field
     * has nothing left to ask them and would only be refused for any other answer. A platform
     * administrator, who has every tenant to choose between, still sees it.
     */
    #[Test]
    public function the_invite_form_asks_an_organization_administrator_for_no_organization(): void
    {
        $this->signedInAdministrator();

        $this->assertNotContains('organization', $this->inviteFormFields());
    }

    #[Test]
    public function the_invite_form_still_asks_a_platform_administrator_for_one(): void
    {
        $this->signedInOperator();

        $this->assertContains('organization', $this->inviteFormFields());
    }

    /**
     * Hiding it decides the matter rather than suggesting it.
     *
     * Nova drops a field the operator may not see before it validates and before it resolves the
     * payload, so an identifier posted by hand is never read: the invitation lands in the
     * operator's own tenant, which is where an absent organization has always sent it.
     */
    #[Test]
    public function an_organization_posted_anyway_is_not_read(): void
    {
        Mail::fake();
        $admin = $this->signedInAdministrator();
        $stranger = Organization::factory()->create();

        $this->post(
            '/nova-api/users/action?action='.app(InviteUser::class)->uriKey(),
            [
                'resources' => '',
                'name' => 'Nieuwe Collega',
                'email' => 'nieuwe.collega@example.com',
                'role' => UserRole::Member->value,
                'organization' => (string) $stranger->getKey(),
            ],
            ['Accept' => 'application/json'],
        )->assertOk();

        $invited = User::query()->where('email', 'nieuwe.collega@example.com')->sole();

        $this->assertSame(UserStatus::Invited, $invited->status);
        $this->assertSame($admin->organization_id, $invited->organization_id);
    }

    /**
     * An organization administrator has one organization, reached from their users' rows, so the
     * menu does not offer a list of it. A platform administrator still gets the entry.
     */
    #[Test]
    public function the_menu_offers_an_organization_administrator_no_organizations(): void
    {
        $this->signedInAdministrator();

        $this->assertNotContains('/nova/resources/organizations', $this->menuPaths());
        $this->assertContains('/nova/resources/users', $this->menuPaths());
    }

    #[Test]
    public function the_menu_offers_a_platform_administrator_the_organizations(): void
    {
        $this->signedInOperator();

        $this->assertContains('/nova/resources/organizations', $this->menuPaths());
    }

    /** The role picker offers an organization administrator nothing the use case would refuse. */
    #[Test]
    public function the_invite_form_offers_an_organization_administrator_no_platform_role(): void
    {
        $this->signedInAdministrator();

        $this->assertSame(
            [UserRole::Member->value, UserRole::Administrator->value],
            $this->inviteRoleOptions(),
        );
    }

    #[Test]
    public function the_invite_form_offers_a_platform_administrator_every_role(): void
    {
        $this->signedInOperator();

        $this->assertSame(UserRole::values(), $this->inviteRoleOptions());
    }

    #[Test]
    public function an_organization_administrator_invites_a_fellow_administrator(): void
    {
        Mail::fake();
        $admin = $this->signedInAdministrator();

        $this->post(
            '/nova-api/users/action?action='.app(InviteUser::class)->uriKey(),
            [
                'resources' => '',
                'name' => 'Nieuwe Beheerder',
                'email' => 'nieuwe.beheerder@example.com',
                'role' => UserRole::Administrator->value,
            ],
            ['Accept' => 'application/json'],
        )->assertOk()->assertJsonMissingPath('danger');

        $invited = User::query()->where('email', 'nieuwe.beheerder@example.com')->sole();

        $this->assertSame(UserRole::Administrator, $invited->role);
        $this->assertSame($admin->organization_id, $invited->organization_id);
    }

    /**
     * The paths the panel's main menu links to, read from the page Nova renders.
     *
     * @return list<string>
     */
    private function menuPaths(): array
    {
        $html = (string) $this->get('/nova/resources/users')->assertOk()->getContent();

        if (preg_match('#<script data-page="app" type="application/json">(.+?)</script>#s', $html, $match) !== 1) {
            self::fail('The panel rendered no page data.');
        }

        $page = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        $menu = is_array($page) ? ($page['props']['novaConfig']['mainMenu'] ?? null) : null;

        if (! is_array($menu)) {
            self::fail('The panel rendered no main menu.');
        }

        $paths = [];
        array_walk_recursive($menu, static function (mixed $value, int|string $key) use (&$paths): void {
            if ($key === 'path' && is_string($value)) {
                $paths[] = $value;
            }
        });

        return $paths;
    }

    /**
     * The values the invite form's role picker offers the signed-in operator.
     *
     * @return list<string>
     */
    private function inviteRoleOptions(): array
    {
        $role = $this->inviteFormField('role');
        $options = $role['options'] ?? null;

        if (! is_array($options)) {
            self::fail('The role picker was offered without options.');
        }

        $values = [];

        foreach ($options as $option) {
            if (is_array($option) && is_string($option['value'] ?? null)) {
                $values[] = $option['value'];
            }
        }

        return $values;
    }

    /**
     * One field of the invite form, as Nova serializes it for the signed-in operator.
     *
     * @return array<mixed>
     */
    private function inviteFormField(string $attribute): array
    {
        foreach ($this->inviteFormFieldsSerialized() as $field) {
            if (($field['attribute'] ?? null) === $attribute) {
                return $field;
            }
        }

        self::fail("The invite form has no {$attribute} field.");
    }

    /**
     * The attributes the invite form asks the signed-in operator for.
     *
     * @return list<string>
     */
    private function inviteFormFields(): array
    {
        $attributes = [];

        foreach ($this->inviteFormFieldsSerialized() as $field) {
            if (is_string($field['attribute'] ?? null)) {
                $attributes[] = $field['attribute'];
            }
        }

        return $attributes;
    }

    /** @return list<array<mixed>> */
    private function inviteFormFieldsSerialized(): array
    {
        $actions = $this->getJson('/nova-api/users/actions')->assertOk()->json('actions');

        if (! is_array($actions)) {
            self::fail('Nova listed no actions at all.');
        }

        $uriKey = app(InviteUser::class)->uriKey();

        foreach ($actions as $action) {
            if (! is_array($action) || ($action['uriKey'] ?? null) !== $uriKey) {
                continue;
            }

            $fields = $action['fields'] ?? null;

            if (! is_array($fields)) {
                self::fail('The invite action was offered without any fields.');
            }

            return array_values(array_filter($fields, is_array(...)));
        }

        self::fail('The invite action was not offered at all.');
    }

    /** @return list<string> */
    private function listedIdentifiers(string $url): array
    {
        $resources = $this->getJson($url)->assertOk()->json('resources');

        if (! is_array($resources)) {
            self::fail('Nova listed no resources at all.');
        }

        $found = [];

        foreach ($resources as $resource) {
            if (is_array($resource) && is_string($resource['id']['value'] ?? null)) {
                $found[] = $resource['id']['value'];
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function offeredActions(string $resource, string $resourceId): array
    {
        $actions = $this->getJson("/nova-api/{$resource}/actions?resourceId={$resourceId}")
            ->assertOk()
            ->json('actions');

        if (! is_array($actions)) {
            self::fail('Nova listed no actions at all.');
        }

        $keys = [];

        foreach ($actions as $action) {
            if (is_array($action) && is_string($action['uriKey'] ?? null)) {
                $keys[] = $action['uriKey'];
            }
        }

        return $keys;
    }

    /** Signs an organization administrator in the way the panel does, through a real link. */
    private function signedInAdministrator(): User
    {
        return $this->signInThroughLink(
            User::factory()->administrator()->for(Organization::factory())->create(),
        );
    }

    /** The other operator the panel admits, for the comparisons an administrator is measured by. */
    private function signedInOperator(): User
    {
        return $this->signInThroughLink(
            User::factory()->platformAdministrator()->for(Organization::factory()->platform())->create(),
        );
    }

    /** Claims a fresh link for the given user, which is the only way into the panel. */
    private function signInThroughLink(User $user): User
    {
        config(['session.driver' => 'database']);

        $secret = app(SecretTokenFactory::class)->create();

        LoginToken::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => $secret->hash,
            'purpose' => LoginTokenPurpose::MagicLink,
            'expires_at' => Carbon::now()->addMinutes(30),
        ]);

        $this->post(route('nova.sign-in.redeem'), ['token' => $secret->value])
            ->assertRedirect(config('nova.path'));

        return $user->refresh();
    }
}
