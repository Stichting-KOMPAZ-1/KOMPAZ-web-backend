<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who may read the generated API documentation.
 *
 * Scramble closes it outside local development. The flag reopens it, and a platform administrator
 * reaches it regardless — which is what lets a deployment leave the flag off without the
 * documentation becoming unreadable to the people who need it.
 */
final class ApiDocsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_documentation_is_closed_when_the_flag_is_off(): void
    {
        config(['kompaz.api_docs_public' => false]);

        $this->get('/docs/api')->assertForbidden();
    }

    #[Test]
    public function the_flag_opens_it(): void
    {
        config(['kompaz.api_docs_public' => true]);

        $this->get('/docs/api')->assertOk();
    }

    #[Test]
    public function a_platform_administrator_reads_it_with_the_flag_off(): void
    {
        config(['kompaz.api_docs_public' => false]);

        $operator = User::factory()->platformAdministrator()
            ->for(Organization::factory()->platform())
            ->create();

        $this->actingAs($operator, 'web')->get('/docs/api')->assertOk();
    }

    #[Test]
    public function an_organization_administrator_does_not(): void
    {
        config(['kompaz.api_docs_public' => false]);

        $this->actingAs(User::factory()->administrator()->create(), 'web')
            ->get('/docs/api')
            ->assertForbidden();
    }
}
