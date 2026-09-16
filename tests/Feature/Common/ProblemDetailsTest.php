<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Models\Organization;
use App\Models\User;
use App\Support\Errors\ProblemDetailFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Everything a caller reads is Dutch; `title` names the status in English from the HTTP
 * specification's own vocabulary, because it is read by whoever is debugging rather than by the
 * person using the product.
 */
final class ProblemDetailsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_validation_failure_carries_the_fields_and_the_forms_own_title(): void
    {
        $platformAdmin = $this->platformAdministrator();

        $response = $this->withHeaders($this->tokenHeaders($platformAdmin))
            ->postJson('/api/organizations', ['name' => ''])
            ->assertStatus(400);

        $response
            ->assertJsonPath('title', ProblemDetailFactory::VALIDATION_TITLE)
            ->assertJsonPath('status', 400)
            ->assertJsonPath('type', 'https://datatracker.ietf.org/doc/html/rfc9110#section-15.5.1')
            ->assertJsonPath('instance', '/api/organizations')
            ->assertJsonStructure(['type', 'title', 'status', 'instance', 'errors', 'traceId']);
    }

    #[Test]
    public function a_forbidden_request_names_the_status_in_english_and_explains_in_dutch(): void
    {
        $member = User::factory()->create();
        $other = Organization::factory()->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->getJson("/api/organizations/{$other->getKey()}")
            ->assertForbidden()
            ->assertJsonPath('title', 'Forbidden')
            ->assertJsonPath('status', 403)
            ->assertJsonPath('detail', 'Deze gebruiker hoort niet bij de opgevraagde organisatie.');
    }

    #[Test]
    public function a_conflict_names_the_status_and_says_what_to_do(): void
    {
        $platformAdmin = $this->platformAdministrator();
        Organization::factory()->create([
            'name' => 'Elkerliek',
            'normalized_name' => Organization::normalize('Elkerliek'),
        ]);

        $this->withHeaders($this->tokenHeaders($platformAdmin))
            ->postJson('/api/organizations', ['name' => 'Elkerliek'])
            ->assertConflict()
            ->assertJsonPath('title', 'Conflict')
            ->assertJsonPath('type', 'https://datatracker.ietf.org/doc/html/rfc9110#section-15.5.10');
    }

    #[Test]
    public function an_unknown_route_is_a_problem_document_too(): void
    {
        $this->getJson('/api/niets-hier')
            ->assertNotFound()
            ->assertJsonPath('title', 'Not Found')
            ->assertJsonPath('status', 404);
    }

    #[Test]
    public function an_unauthenticated_request_says_so_without_explaining(): void
    {
        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('title', 'Unauthorized')
            ->assertJsonMissingPath('detail');
    }

    #[Test]
    public function the_response_carries_the_problem_media_type_and_a_trace_id(): void
    {
        $response = $this->getJson('/api/auth/me')->assertUnauthorized();

        $this->assertStringStartsWith('application/problem+json', $response->headers->get('Content-Type') ?? '');
        $this->assertNotEmpty($response->json('traceId'));
    }

    #[Test]
    public function a_caller_supplied_request_id_becomes_the_trace_id(): void
    {
        $response = $this->withHeaders(['X-Request-Id' => 'van-de-proxy'])
            ->getJson('/api/auth/me')
            ->assertUnauthorized();

        $this->assertSame('van-de-proxy', $response->json('traceId'));
        $this->assertSame('van-de-proxy', $response->headers->get('X-Request-Id'));
    }

    private function platformAdministrator(): User
    {
        return User::factory()->platformAdministrator()
            ->for(Organization::factory()->platform())
            ->create();
    }
}
