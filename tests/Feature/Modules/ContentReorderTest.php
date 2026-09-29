<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Enums\LoginTokenPurpose;
use App\Models\Chapter;
use App\Models\ELearning;
use App\Models\LoginToken;
use App\Models\Organization;
use App\Models\Step;
use App\Models\User;
use App\Services\SecretTokenFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dragging a course's chapters and a chapter's steps into order.
 *
 * The drag-and-drop is `outl1ne/nova-sortable`'s, but the addresses it posts to are ours: the
 * package's own controller authenticates nobody and calls whatever relationship the request
 * names. Half of these tests are about the doors it left open being shut.
 */
final class ContentReorderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_chapter_table_on_a_course_offers_dragging(): void
    {
        $this->signedInOperator();
        $course = ELearning::factory()->create();
        Chapter::factory()->of($course)->create();

        $row = $this->getJson('/nova-api/chapters?viaResource=e-learnings&viaResourceId='
            .$course->getKey().'&viaRelationship=chapters&relationshipType=hasMany')
            ->assertOk()
            ->json('resources.0');

        $this->assertIsArray($row);
        $this->assertTrue($row['sort_on_has_many'] ?? false);
    }

    #[Test]
    public function dragging_steps_puts_them_in_the_new_order(): void
    {
        $this->signedInOperator();
        $chapter = Chapter::factory()->create();
        [$first, $second, $third] = $this->stepsOf($chapter, 3);

        $this->reorderSteps($chapter, [$third, $first, $second])->assertNoContent();

        $this->assertSame([$third, $first, $second], $this->stepOrder($chapter));
    }

    #[Test]
    public function a_step_dragged_down_and_back_up_ends_where_it_started(): void
    {
        $this->signedInOperator();
        $chapter = Chapter::factory()->create();
        [$first, $second, $third] = $this->stepsOf($chapter, 3);

        $this->reorderSteps($chapter, [$first, $third, $second])->assertNoContent();
        $this->assertSame([$first, $third, $second], $this->stepOrder($chapter));

        $this->reorderSteps($chapter, [$first, $second, $third])->assertNoContent();
        $this->assertSame([$first, $second, $third], $this->stepOrder($chapter));
    }

    #[Test]
    public function a_courses_chapters_can_be_dragged_back_and_forth(): void
    {
        $this->signedInOperator();
        $course = ELearning::factory()->create();
        $chapters = [];

        for ($position = 0; $position < 3; $position++) {
            $chapters[] = (string) Chapter::factory()->of($course, $position)->create()->getKey();
        }

        [$first, $second, $third] = $chapters;
        $via = [
            'viaResource' => 'e-learnings',
            'viaResourceId' => (string) $course->getKey(),
            'viaRelationship' => 'chapters',
            'relationshipType' => 'hasMany',
        ];
        $order = fn (): array => array_values(array_map('strval', $course->chapters()->orderBy('id')->pluck('id')->all()));

        $this->postJson('/nova-vendor/nova-sortable/sort/chapters/update-order', $via + ['resourceIds' => [$first, $third, $second]])
            ->assertNoContent();
        $this->assertSame([$first, $third, $second], $order());

        $this->postJson('/nova-vendor/nova-sortable/sort/chapters/update-order', $via + ['resourceIds' => [$first, $second, $third]])
            ->assertNoContent();
        $this->assertSame([$first, $second, $third], $order());
    }

    #[Test]
    public function dragging_within_one_page_leaves_the_rest_of_the_list_where_it_was(): void
    {
        // The table sends the rows on the page it shows. For a long list that is a slice, and
        // the rows on the other pages must not move.
        $this->signedInOperator();
        $chapter = Chapter::factory()->create();
        [$a, $b, $c, $d] = $this->stepsOf($chapter, 4);

        $this->reorderSteps($chapter, [$c, $b])->assertNoContent();

        $this->assertSame([$a, $c, $b, $d], $this->stepOrder($chapter));
    }

    #[Test]
    public function a_step_can_be_moved_to_either_end(): void
    {
        $this->signedInOperator();
        $chapter = Chapter::factory()->create();
        [$a, $b, $c] = $this->stepsOf($chapter, 3);

        $this->postJson('/nova-vendor/nova-sortable/sort/steps/move-to-start', $this->via($chapter) + ['resourceId' => $c])
            ->assertNoContent();

        $this->assertSame([$c, $a, $b], $this->stepOrder($chapter));

        $this->postJson('/nova-vendor/nova-sortable/sort/steps/move-to-end', $this->via($chapter) + ['resourceId' => $c])
            ->assertNoContent();

        $this->assertSame([$a, $b, $c], $this->stepOrder($chapter));
    }

    #[Test]
    public function a_step_from_another_chapter_cannot_be_dragged_into_this_one(): void
    {
        $this->signedInOperator();
        $chapter = Chapter::factory()->create();
        [$a, $b] = $this->stepsOf($chapter, 2);
        $foreign = Step::factory()->create(['position' => 7]);

        $this->reorderSteps($chapter, [$b, (string) $foreign->getKey(), $a])->assertNotFound();

        $this->assertSame([$a, $b], $this->stepOrder($chapter));
        $this->assertSame(7, $foreign->fresh()?->position);
    }

    #[Test]
    public function an_organization_administrator_cannot_reorder_a_course(): void
    {
        $this->signInThroughLink(User::factory()->administrator()->for(Organization::factory())->create());
        $chapter = Chapter::factory()->create();
        [$a, $b] = $this->stepsOf($chapter, 2);

        $this->reorderSteps($chapter, [$b, $a])->assertForbidden();

        $this->assertSame([$a, $b], $this->stepOrder($chapter));
    }

    #[Test]
    public function nobody_signed_out_reaches_it(): void
    {
        // The package's own routes carried no authentication at all.
        $chapter = Chapter::factory()->create();
        [$a, $b] = $this->stepsOf($chapter, 2);

        $this->reorderSteps($chapter, [$b, $a])->assertUnauthorized();

        $this->assertSame([$a, $b], $this->stepOrder($chapter));
    }

    #[Test]
    public function the_request_cannot_name_its_own_relationship(): void
    {
        // The package called `$parent->{$viaRelationship}()` on whatever record the request
        // named. `delete` is a method too.
        $this->signedInOperator();
        $organization = Organization::factory()->create();

        $this->postJson('/nova-vendor/nova-sortable/sort/users/update-order', [
            'resourceIds' => ['x'],
            'viaResource' => 'organizations',
            'viaResourceId' => (string) $organization->getKey(),
            'viaRelationship' => 'delete',
            'relationshipType' => 'hasMany',
        ])->assertNotFound();

        $this->assertModelExists($organization);
    }

    /** @return list<string> */
    private function stepsOf(Chapter $chapter, int $count): array
    {
        $keys = [];

        for ($position = 0; $position < $count; $position++) {
            $keys[] = (string) Step::factory()->of($chapter, $position)->create()->getKey();
        }

        return $keys;
    }

    /** @return list<string> */
    private function stepOrder(Chapter $chapter): array
    {
        return array_values(array_map('strval', $chapter->steps()->orderBy('id')->pluck('id')->all()));
    }

    /**
     * @param  list<string>  $ids
     * @return TestResponse<Response>
     */
    private function reorderSteps(Chapter $chapter, array $ids): TestResponse
    {
        return $this->postJson('/nova-vendor/nova-sortable/sort/steps/update-order', $this->via($chapter) + [
            'resourceIds' => $ids,
        ]);
    }

    /** @return array<string, string> */
    private function via(Chapter $chapter): array
    {
        return [
            'viaResource' => 'chapters',
            'viaResourceId' => (string) $chapter->getKey(),
            'viaRelationship' => 'steps',
            'relationshipType' => 'hasMany',
        ];
    }

    private function signedInOperator(): User
    {
        return $this->signInThroughLink(
            User::factory()->platformAdministrator()->for(Organization::factory()->platform())->create(),
        );
    }

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

        $this->get(route('nova.sign-in.claim', ['token' => $secret->value]))
            ->assertRedirect(config('nova.path'));

        return $user->refresh();
    }
}
