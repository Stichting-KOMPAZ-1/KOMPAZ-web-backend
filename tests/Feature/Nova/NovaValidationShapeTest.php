<?php

declare(strict_types=1);

namespace Tests\Feature\Nova;

use App\Enums\LoginTokenPurpose;
use App\Models\LoginToken;
use App\Models\Organization;
use App\Models\User;
use App\Nova\Actions\CreateOrganization;
use App\Services\SecretTokenFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The panel gets Laravel's validation shape; the API gets problem details.
 *
 * These are two different audiences and the difference is not cosmetic. Nova's frontend binds
 * field errors from a **422** carrying `errors`, and shows a banner for anything else — and a
 * banner closes the dialog, which rule 18 says must not happen for a refusal about something the
 * operator typed, because a closed dialog means retyping a form to correct one word.
 *
 * Nova asks for JSON on every request it makes, so the `expectsJson()` arm of the problem-details
 * renderer swept the whole panel in and answered its forms with a 400 instead. Nothing caught it:
 * `refusalField` existed, was used twice, and had no test. This is that test, from both ends —
 * the panel's shape and the API's — because a fix to one that broke the other would be worse than
 * the bug.
 */
final class NovaValidationShapeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_refusal_about_a_typed_field_reaches_the_panel_as_a_field_error(): void
    {
        $this->signedInOperator();

        Organization::factory()->create(['name' => 'Waardenland', 'normalized_name' => 'WAARDENLAND']);

        $this->post(
            '/nova-api/organizations/action?action='.app(CreateOrganization::class)->uriKey(),
            // Differing only by case, which the folded unique index refuses.
            ['resources' => '', 'name' => 'waardenland'],
            ['Accept' => 'application/json'],
        )
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('name');
    }

    #[Test]
    public function the_api_still_answers_a_validation_failure_with_problem_details(): void
    {
        // The other half of the fix. Rule 16: this API answers 400, not Laravel's 422, and every
        // refusal is application/problem+json.
        $operator = $this->signedInOperator();

        $this->withHeaders($this->tokenHeaders($operator))
            ->postJson('/api/organizations', ['name' => ''])
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    private function signedInOperator(): User
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->platformAdministrator()
            ->for(Organization::factory()->platform())->create();

        $secret = app(SecretTokenFactory::class)->create();

        LoginToken::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => $secret->hash,
            'purpose' => LoginTokenPurpose::MagicLink,
            'expires_at' => Carbon::now()->addMinutes(30),
        ]);

        $this->get(route('nova.sign-in.claim', ['token' => $secret->value]))
            ->assertRedirect(config('nova.path'));

        return $user->refresh();
    }
}
