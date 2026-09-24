<?php

declare(strict_types=1);

namespace Tests\Feature\Nova;

use App\Enums\LoginTokenPurpose;
use App\Enums\RosterStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\InvitationMail;
use App\Models\LoginToken;
use App\Models\Organization;
use App\Models\OrganizationLogo;
use App\Models\User;
use App\Nova\Actions\CreateOrganization;
use App\Nova\Actions\DeleteUser;
use App\Nova\Actions\InviteUser;
use App\Nova\Actions\RestoreUser;
use App\Nova\Actions\UpdateOrganization;
use App\Nova\Actions\UpdateUser;
use App\Nova\Actions\UploadOrganizationLogo;
use App\Nova\Filters\UserDeletionState;
use App\Services\SecretTokenFactory;
use App\Support\Images\LogoImage;
use App\Support\Organizations\OrganizationMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Nova\Actions\Action;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The panel's operations.
 *
 * Each write is a button on top of the use case the API calls, so what these assert is not the
 * rules themselves — those are tested where they live — but that the panel reaches them: that a
 * rule still refuses an operator, in the same words, and that a refusal arrives as something an
 * operator can read rather than as a 500. The one read with machinery of its own, the logo, is
 * here for the same reason: it answers a session where the API answers a token.
 */
final class NovaOperationsTest extends TestCase
{
    use RefreshDatabase;

    private const string PNG = "\x89PNG\r\n\x1a\n".'the rest does not matter';

    #[Test]
    public function the_panel_offers_the_same_write_operations_the_api_has(): void
    {
        $operator = $this->signedInOperator();

        $this->assertSame(
            ['gebruiker-uitnodigen', 'gebruiker-wijzigen', 'uitnodiging-opnieuw-versturen', 'gebruiker-herstellen', 'gebruiker-verwijderen'],
            $this->offeredActions('users', (string) $operator->getKey()),
        );

        $this->assertSame(
            ['organisatie-aanmaken', 'organisatie-wijzigen', 'logo-uploaden', 'logo-verwijderen', 'organisatie-archiveren', 'organisatie-activeren', 'organisatie-verwijderen'],
            $this->offeredActions('organizations', (string) $operator->organization_id),
        );
    }

    #[Test]
    public function an_invitation_is_not_an_account_to_edit(): void
    {
        $this->signedInOperator();
        $invited = User::factory()->invited()->create();
        $member = User::factory()->create();

        // Everything else still applies to the row: the invitation can be resent, and it can be
        // withdrawn. What is not offered is editing, because there is no account behind it yet.
        $this->assertSame(
            ['gebruiker-uitnodigen', 'uitnodiging-opnieuw-versturen', 'gebruiker-verwijderen'],
            $this->runnableActions('users', (string) $invited->getKey()),
        );

        $this->assertContains('gebruiker-wijzigen', $this->runnableActions('users', (string) $member->getKey()));
    }

    #[Test]
    public function an_accepted_invitation_has_nothing_left_to_resend(): void
    {
        $this->signedInOperator();
        $member = User::factory()->create();

        // The use case refuses this one anyway, so offering it would promise a fresh mail and then
        // answer with a banner saying the invitation was already accepted.
        $this->assertNotContains(
            'uitnodiging-opnieuw-versturen',
            $this->runnableActions('users', (string) $member->getKey()),
        );
    }

    #[Test]
    public function withdrawing_an_invitation_from_the_panel_removes_it(): void
    {
        Mail::fake();
        $this->signedInOperator();
        $invited = User::factory()->invited()->create();

        $this->runAction('users', DeleteUser::class, ['resources' => (string) $invited->getKey()])
            ->assertOk()
            ->assertJsonPath('message', __('nova.actions.delete_user.message'));

        // The panel spends the same use case the API does, so the row is gone rather than marked.
        $this->assertNull(User::withTrashed()->find($invited->getKey()));
    }

    #[Test]
    public function the_roster_says_in_dutch_where_somebody_stands(): void
    {
        $operator = $this->signedInOperator();

        $invited = User::factory()->invited()->create();
        $expired = User::factory()->invited()->create();

        foreach ([[$invited, 1], [$expired, -1]] as [$user, $days]) {
            LoginToken::query()->create([
                'user_id' => $user->getKey(),
                'token_hash' => app(SecretTokenFactory::class)->create()->hash,
                'purpose' => LoginTokenPurpose::Invitation,
                'expires_at' => Carbon::now()->addDays($days),
            ]);
        }

        $statuses = $this->rosterStatuses();

        $this->assertSame(RosterStatus::Active->value, $statuses[(string) $operator->getKey()]);
        $this->assertSame(RosterStatus::Invited->value, $statuses[(string) $invited->getKey()]);
        // Nothing was written when the link ran out — the roster works it out from the invitation.
        $this->assertSame(RosterStatus::Expired->value, $statuses[(string) $expired->getKey()]);
    }

    #[Test]
    public function nova_s_own_forms_stay_off(): void
    {
        // The operations above are the only way in. Nova's create, edit and delete write columns
        // straight to the database, which is what they must never do here.
        $this->assertFalse(\App\Nova\User::authorizedToCreate(request()));
        $this->assertFalse(\App\Nova\Organization::authorizedToCreate(request()));
    }

    #[Test]
    public function inviting_somebody_goes_through_the_use_case(): void
    {
        Mail::fake();
        $operator = $this->signedInOperator();
        $tenant = Organization::factory()->create();

        $this->runAction('users', InviteUser::class, [
            'resources' => '',
            'name' => 'Nieuwe Collega',
            'email' => 'Nieuwe.Collega@Example.COM',
            'role' => UserRole::Member->value,
            'organization' => $tenant->getKey(),
        ])->assertOk()->assertJsonPath('message', __('nova.actions.invite_user.message'));

        $invited = User::query()->where('email', 'Nieuwe.Collega@Example.COM')->sole();

        $this->assertSame(UserStatus::Invited, $invited->status);
        $this->assertSame($tenant->getKey(), $invited->organization_id);
        // The folded column carries the unique index and every lookup, so a panel that wrote only
        // `email` would leave somebody unable to sign in.
        $this->assertSame(User::normalize('Nieuwe.Collega@Example.COM'), $invited->normalized_email);
        $this->assertSame(1, LoginToken::query()->where('user_id', $invited->getKey())->count());
        Mail::assertSent(InvitationMail::class);

        $this->assertNotSame($operator->getKey(), $invited->getKey());
    }

    #[Test]
    public function a_refused_operation_answers_the_operator_instead_of_failing(): void
    {
        $operator = $this->signedInOperator();

        // Deleting your own account is refused by the use case, in Dutch, and that sentence is
        // what the panel has to show — not a stack trace.
        $this->runAction('users', DeleteUser::class, ['resources' => (string) $operator->getKey()])
            ->assertOk()
            ->assertJsonPath('danger', 'Een gebruiker kan het eigen account niet verwijderen.');

        $this->assertFalse($operator->fresh()?->isDeleted());
    }

    #[Test]
    public function a_name_already_taken_is_answered_in_the_words_the_api_uses(): void
    {
        $operator = $this->signedInOperator();
        Organization::factory()->create([
            'name' => 'Elkerliek',
            'normalized_name' => Organization::normalize('Elkerliek'),
        ]);

        // Folded, so a name differing only in case is the same name — and the sentence lives in a
        // constant precisely so both the API and the panel say it.
        //
        // Answered under the field rather than as a banner: Nova closes the dialog on a banner and
        // leaves it open on a field error, and the operator has one word to change.
        $this->runAction('organizations', CreateOrganization::class, [
            'resources' => '',
            'name' => 'elkerliek',
        ])->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('errors.name.0', OrganizationMessages::NAME_TAKEN);

        $this->assertSame(1, Organization::query()->where('normalized_name', Organization::normalize('Elkerliek'))->count());
        $this->assertNotNull($operator->fresh());
    }

    #[Test]
    public function renaming_onto_a_name_already_taken_keeps_the_dialog_open_too(): void
    {
        $this->signedInOperator();
        Organization::factory()->create([
            'name' => 'Elkerliek',
            'normalized_name' => Organization::normalize('Elkerliek'),
        ]);
        $other = Organization::factory()->create();

        $this->runAction('organizations', UpdateOrganization::class, [
            'resources' => (string) $other->getKey(),
            'name' => 'ELKERLIEK',
        ])->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('errors.name.0', OrganizationMessages::NAME_TAKEN);

        $this->assertSame($other->name, $other->fresh()?->name);
    }

    #[Test]
    public function a_missing_name_is_answered_in_the_products_own_words(): void
    {
        $this->signedInOperator();

        // Not the framework's sentence about a field called "name": the panel is the product
        // talking, and this is the copy the product chose.
        $this->runAction('organizations', CreateOrganization::class, [
            'resources' => '',
            'name' => '   ',
        ])->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('errors.name.0', OrganizationMessages::NAME_REQUIRED);

        $this->assertSame(1, Organization::query()->count());
    }

    #[Test]
    public function an_organization_can_be_created_with_its_logo_in_one_go(): void
    {
        $this->signedInOperator();

        $this->runAction('organizations', CreateOrganization::class, [
            'resources' => '',
            'name' => 'Elkerliek',
            'logo' => UploadedFile::fake()->createWithContent('logo.png', self::PNG),
        ])->assertOk()->assertJsonPath('message', __('nova.actions.create_organization.message'));

        $created = Organization::query()->where('normalized_name', Organization::normalize('Elkerliek'))->sole();

        // Stored against the organization the same dialog created, and labelled with what the
        // bytes are rather than with what the upload called itself.
        $this->assertSame(LogoImage::PNG, $created->logo?->content_type);
    }

    #[Test]
    public function creating_an_organization_without_a_logo_leaves_it_on_the_placeholder(): void
    {
        $this->signedInOperator();

        $this->runAction('organizations', CreateOrganization::class, [
            'resources' => '',
            'name' => 'Elkerliek',
        ])->assertOk();

        $created = Organization::query()->where('normalized_name', Organization::normalize('Elkerliek'))->sole();

        $this->assertNull($created->logo);

        // Which is not an organization without an image: the panel answers with the placeholder,
        // and the list it now appears on asks for exactly this.
        $this->get(route('nova.organization-logo', ['organization' => $created->getKey()]))
            ->assertOk()
            ->assertHeader('Content-Type', LogoImage::SVG);
    }

    #[Test]
    public function a_logo_offered_while_creating_is_judged_by_the_same_rules_as_one_uploaded_later(): void
    {
        $this->signedInOperator();

        $this->runAction('organizations', CreateOrganization::class, [
            'resources' => '',
            'name' => 'Elkerliek',
            'logo' => UploadedFile::fake()->createWithContent('payload.png', '<html><svg></svg></html>'),
        ])->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('errors.logo.0', OrganizationMessages::logoWrongFormat());

        // Refused before anything was written, so a rejected logo does not leave an organization
        // behind for the operator to trip over when they try again.
        $this->assertSame(1, Organization::query()->count());
    }

    #[Test]
    public function an_operator_deletes_and_restores_somebody(): void
    {
        Mail::fake();
        $this->signedInOperator();
        $member = User::factory()->create();

        $this->runAction('users', DeleteUser::class, ['resources' => (string) $member->getKey()])
            ->assertOk()
            ->assertJsonPath('message', __('nova.actions.delete_user.message'));

        $this->assertTrue(User::query()->withTrashed()->findOrFail($member->getKey())->isDeleted());

        $this->runAction('users', RestoreUser::class, ['resources' => (string) $member->getKey()])
            ->assertOk()
            ->assertJsonPath('message', __('nova.actions.restore_user.message'));

        $this->assertFalse(User::query()->findOrFail($member->getKey())->isDeleted());
    }

    #[Test]
    public function editing_somebody_keeps_the_folded_email_column_in_step(): void
    {
        $this->signedInOperator();
        $member = User::factory()->create();

        $this->runAction('users', UpdateUser::class, [
            'resources' => (string) $member->getKey(),
            'name' => 'Nieuwe Naam',
            'email' => 'Nieuw.Adres@Example.COM',
            'role' => '',
            'organization' => '',
        ])->assertOk()->assertJsonPath('message', __('nova.actions.update_user.message'));

        $updated = $member->fresh();

        $this->assertNotNull($updated);
        $this->assertSame('Nieuwe Naam', $updated->name);
        $this->assertSame(User::normalize('Nieuw.Adres@Example.COM'), $updated->normalized_email);
        // Left empty means "not part of this change", exactly as the API's nullable role does.
        $this->assertSame($member->role, $updated->role);
    }

    #[Test]
    public function a_logo_upload_is_judged_on_its_bytes_and_answered_like_the_api(): void
    {
        $operator = $this->signedInOperator();

        $this->runAction('organizations', UploadOrganizationLogo::class, [
            'resources' => (string) $operator->organization_id,
            'logo' => UploadedFile::fake()->createWithContent('payload.png', '<html><svg></svg></html>'),
        ])->assertStatus(400)->assertJsonPath('errors.logo.0', OrganizationMessages::logoWrongFormat());

        $this->assertSame(0, OrganizationLogo::query()->count());

        $this->runAction('organizations', UploadOrganizationLogo::class, [
            'resources' => (string) $operator->organization_id,
            'logo' => UploadedFile::fake()->createWithContent('logo.png', self::PNG),
        ])->assertOk()->assertJsonPath('message', __('nova.actions.upload_organization_logo.message'));

        // The stored media type is read out of the bytes, never taken from the upload.
        $this->assertSame(LogoImage::PNG, OrganizationLogo::query()->sole()->content_type);
    }

    #[Test]
    public function the_panel_serves_the_logo_an_organization_has(): void
    {
        $operator = $this->signedInOperator();

        $this->runAction('organizations', UploadOrganizationLogo::class, [
            'resources' => (string) $operator->organization_id,
            'logo' => UploadedFile::fake()->createWithContent('logo.png', self::PNG),
        ])->assertOk();

        $response = $this->get(route('nova.organization-logo', ['organization' => $operator->organization_id]))
            ->assertOk()
            ->assertHeader('Content-Type', LogoImage::PNG)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");

        $this->assertSame(self::PNG, $response->baseResponse->getContent());
    }

    #[Test]
    public function an_organization_without_a_logo_shows_the_operator_the_placeholder(): void
    {
        $operator = $this->signedInOperator();

        $this->get(route('nova.organization-logo', ['organization' => $operator->organization_id]))
            ->assertOk()
            ->assertHeader('Content-Type', LogoImage::SVG);
    }

    #[Test]
    public function the_panel_logo_is_closed_to_anybody_but_an_operator(): void
    {
        $organization = Organization::factory()->create();

        // A guest is sent to the panel's sign-in, and somebody who is signed in but not an
        // operator is refused by the same gate that keeps them off every other page here.
        $this->get(route('nova.organization-logo', ['organization' => $organization->getKey()]))
            ->assertRedirect(route('nova.sign-in'));

        $this->actingAs(User::factory()->administrator()->for($organization)->create())
            ->get(route('nova.organization-logo', ['organization' => $organization->getKey()]))
            ->assertForbidden();
    }

    /**
     * Signs an operator in the way the panel does, so the session the actions run under is a real
     * one rather than `actingAs`.
     */
    #[Test]
    public function the_roster_is_the_people_who_are_still_here(): void
    {
        $operator = $this->signedInOperator();
        User::factory()->create()->delete();

        // Everything else a user has — when they were invited, when they activated, when they last
        // signed in — is on their own page. The roster is what an operator scans to find somebody,
        // and a deleted account is not what they are scanning for.
        $this->assertSame(
            [(string) $operator->getKey() => ['Naam', 'E-mailadres', 'Organisatie', 'Rol', 'Status']],
            $this->rosterColumns(),
        );
    }

    #[Test]
    public function a_deleted_user_is_found_through_the_filter_rather_than_a_column(): void
    {
        $operator = $this->signedInOperator();
        $deleted = User::factory()->create();
        $deleted->delete();

        // One side or the other, never both: which list an operator is looking at is what says
        // whether these rows are deleted, so no row has to carry the answer itself.
        $this->assertSame([(string) $deleted->getKey()], array_keys($this->rosterColumns('deleted')));
        $this->assertSame([(string) $operator->getKey()], array_keys($this->rosterColumns('active')));

        // And the one it is there for still works on the row it finds.
        $this->assertContains('gebruiker-herstellen', $this->runnableActions('users', (string) $deleted->getKey()));
    }

    #[Test]
    public function the_panel_cannot_restore_or_erase_somebody_behind_the_use_case(): void
    {
        $this->signedInOperator();
        $deleted = User::factory()->create();
        $deleted->delete();

        // Nova grants its own restore and force-delete buttons to every soft-deleting resource
        // unless a policy refuses them, and there is no policy here. Declaring the resource not to
        // soft-delete is what withholds both, leaving the action as the only way back.
        $payload = $this->getJson('/nova-api/users/'.$deleted->getKey())->assertOk();

        $this->assertFalse($payload->json('resource.softDeletes'));
        $this->assertFalse($payload->json('resource.authorizedToRestore'));
        $this->assertFalse($payload->json('resource.authorizedToForceDelete'));
    }

    private function signedInOperator(): User
    {
        config(['session.driver' => 'database']);

        $operator = User::factory()->platformAdministrator()
            ->for(Organization::factory()->platform())
            ->create();

        $secret = app(SecretTokenFactory::class)->create();

        LoginToken::query()->create([
            'user_id' => $operator->getKey(),
            'token_hash' => $secret->hash,
            'purpose' => LoginTokenPurpose::MagicLink,
            'expires_at' => Carbon::now()->addMinutes(30),
        ]);

        $this->get(route('nova.sign-in.claim', ['token' => $secret->value]))
            ->assertRedirect(config('nova.path'));

        return $operator->refresh();
    }

    /**
     * The operations the panel offers for a resource, by the key Nova posts back.
     *
     * Read from Nova rather than hardcoded against the class list, because an action that is
     * registered but not reachable is exactly the failure worth catching.
     *
     * @return array<int, string>
     */
    private function offeredActions(string $resource, string $resourceId): array
    {
        /** @var array<int, array{uriKey: string}> $actions */
        $actions = $this->getJson("/nova-api/{$resource}/actions?resourceId={$resourceId}")
            ->assertOk()
            ->json('actions');

        return array_column($actions, 'uriKey');
    }

    /**
     * The operations the panel offers *and* lets an operator press on this particular row.
     *
     * Nova lists every registered action whatever the row is and marks each one as runnable or
     * not, so this is the list an operator can actually act on — which is where "no editing an
     * invitation" has to show up.
     *
     * @return array<int, string>
     */
    private function runnableActions(string $resource, string $resourceId): array
    {
        /** @var array<int, array{uriKey: string, authorizedToRun: bool}> $actions */
        $actions = $this->getJson("/nova-api/{$resource}/actions?resourceId={$resourceId}")
            ->assertOk()
            ->json('actions');

        return array_column(
            array_filter($actions, static fn (array $action): bool => $action['authorizedToRun']),
            'uriKey',
        );
    }

    /**
     * The columns the roster serves, by user identifier, optionally through the deletion filter.
     *
     * Read out of the index Nova actually answers rather than off the field list, because what an
     * operator is given to scan is the point — a field that is registered but shown somewhere else
     * is exactly the difference being asserted.
     *
     * @return array<string, array<int, string>>
     */
    private function rosterColumns(?string $deletionState = null): array
    {
        $query = $deletionState === null ? '' : '?filters='.base64_encode(
            (string) json_encode([[UserDeletionState::class => $deletionState]]),
        );

        /** @var array<int, array{id: array{value: string}, fields: array<int, array{name: string}>}> $resources */
        $resources = $this->getJson('/nova-api/users'.$query)->assertOk()->json('resources');

        $columns = [];

        foreach ($resources as $resource) {
            $columns[$resource['id']['value']] = array_column($resource['fields'], 'name');
        }

        return $columns;
    }

    /**
     * The status the roster shows, by user identifier.
     *
     * Read out of the index Nova actually serves, because the point of the field is what an
     * operator sees there rather than what the column says.
     *
     * @return array<string, string>
     */
    private function rosterStatuses(): array
    {
        /** @var array<int, array{id: array{value: string}, fields: array<int, array{attribute: string, value: mixed}>}> $resources */
        $resources = $this->getJson('/nova-api/users')->assertOk()->json('resources');

        $statuses = [];

        foreach ($resources as $resource) {
            foreach ($resource['fields'] as $field) {
                if ($field['attribute'] === 'status') {
                    $statuses[$resource['id']['value']] = (string) $field['value'];
                }
            }
        }

        return $statuses;
    }

    /**
     * @param  class-string<Action>  $action
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function runAction(string $resource, string $action, array $payload)
    {
        return $this->post(
            sprintf('/nova-api/%s/action?action=%s', $resource, app($action)->uriKey()),
            $payload,
            ['Accept' => 'application/json'],
        );
    }
}
