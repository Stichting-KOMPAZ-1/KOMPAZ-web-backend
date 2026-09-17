<?php

declare(strict_types=1);

namespace Tests\Feature\Nova;

use App\Enums\LoginTokenPurpose;
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
use App\Nova\Actions\UpdateUser;
use App\Nova\Actions\UploadOrganizationLogo;
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
 * The panel's write operations.
 *
 * Each one is a button on top of the use case the API calls, so what these assert is not the rules
 * themselves — those are tested where they live — but that the panel reaches them: that a rule
 * still refuses an operator, in the same words, and that a refusal arrives as something an
 * operator can read rather than as a 500.
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
            ['organisatie-aanmaken', 'organisatie-wijzigen', 'logo-uploaden', 'logo-verwijderen', 'organisatie-verwijderen'],
            $this->offeredActions('organizations', (string) $operator->organization_id),
        );
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
        $this->runAction('organizations', CreateOrganization::class, [
            'resources' => '',
            'name' => 'elkerliek',
        ])->assertOk()->assertJsonPath('danger', OrganizationMessages::NAME_TAKEN);

        $this->assertSame(1, Organization::query()->where('normalized_name', Organization::normalize('Elkerliek'))->count());
        $this->assertNotNull($operator->fresh());
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

    /**
     * Signs an operator in the way the panel does, so the session the actions run under is a real
     * one rather than `actingAs`.
     */
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
