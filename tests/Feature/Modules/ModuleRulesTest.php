<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Enums\LoginTokenPurpose;
use App\Enums\ModuleStatus;
use App\Models\ELearning;
use App\Models\LoginToken;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\Organization;
use App\Models\User;
use App\Nova\Actions\DeleteModule;
use App\Services\SecretTokenFactory;
use App\Support\Modules\ModuleMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The rules the tickets state in words, which nothing else was enforcing.
 *
 * Each of these was a gap: the counts the product picked, the sentence an operator reads before a
 * deletion that cannot be undone, and the search the organization administrator's table was
 * supposed to have. None of them is expressible as a constraint on a row, which is exactly why
 * they went missing.
 */
final class ModuleRulesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function more_videos_than_the_product_allows_is_refused_in_dutch(): void
    {
        $this->signedInOperator();
        $module = Module::factory()->create();

        $response = $this->putJson("/nova-api/modules/{$module->getKey()}", $this->moduleForm($module, [
            'videos' => $this->rows(ModuleMessages::maximumVideos() + 1),
        ]))->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

        $response->assertJsonPath('errors.videos.0', ModuleMessages::tooManyVideos());
    }

    #[Test]
    public function exactly_the_maximum_is_accepted(): void
    {
        // The other side of the limit, so the rule is off-by-one in neither direction.
        $this->signedInOperator();
        $module = Module::factory()->create();

        $this->putJson("/nova-api/modules/{$module->getKey()}", $this->moduleForm($module, [
            'videos' => $this->rows(ModuleMessages::maximumVideos()),
        ]))->assertSuccessful();

        $this->assertSame(ModuleMessages::maximumVideos(), $module->videos()->count());
    }

    #[Test]
    public function more_links_than_the_product_allows_is_refused(): void
    {
        $this->signedInOperator();
        $module = Module::factory()->create();

        $this->putJson("/nova-api/modules/{$module->getKey()}", $this->moduleForm($module, [
            'links' => $this->rows(ModuleMessages::maximumLinks() + 1, 'module-link-repeatable'),
        ]))
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('errors.links.0', ModuleMessages::tooManyLinks());
    }

    #[Test]
    public function the_deletion_warning_is_the_sentence_the_product_wrote(): void
    {
        // Word for word, because it is a promise: it tells an operator that this cannot be undone
        // and that the courses inside the module will survive it.
        $this->assertSame(
            'Weet je zeker dat je deze module wilt verwijderen? '
            .'De module wordt volledig uit het systeem gehaald en zal niet zichtbaar meer zijn voor organisaties. '
            .'Herstellen is daarna niet meer mogelijk. '
            .'De e-learnings binnen deze module worden NIET verwijderd. '
            .'Deze kunnen herbruikt worden in andere modules, of los verwijderd worden.',
            ModuleMessages::DELETE_MODULE_CONFIRMATION,
        );
    }

    #[Test]
    public function the_panel_offers_that_warning_and_not_novas_own(): void
    {
        $this->signedInOperator();
        $module = Module::factory()->create();

        $actions = $this->getJson("/nova-api/modules/actions?resourceId={$module->getKey()}")
            ->assertOk()
            ->json('actions');

        $this->assertIsArray($actions);

        $delete = collect($actions)->firstWhere('uriKey', app(DeleteModule::class)->uriKey());

        $this->assertNotNull($delete, 'The panel does not offer deleting a module at all.');
        $this->assertSame(ModuleMessages::DELETE_MODULE_CONFIRMATION, $delete['confirmText']);
    }

    #[Test]
    public function deleting_through_that_action_removes_the_module_and_keeps_its_courses(): void
    {
        $this->signedInOperator();
        $module = Module::factory()->create();
        $course = ELearning::factory()->create();
        $module->eLearnings()->attach($course);

        $this->post(
            '/nova-api/modules/action?action='.app(DeleteModule::class)->uriKey(),
            ['resources' => (string) $module->getKey()],
            ['Accept' => 'application/json'],
        )->assertOk();

        $this->assertModelMissing($module);
        $this->assertModelExists($course);
    }

    #[Test]
    public function an_organization_administrator_can_search_their_table_by_module_name(): void
    {
        // The table shows a module's name, so that is what an operator types — and it is not a
        // column on the row Nova is searching.
        $admin = $this->signedInAdministrator();

        $wanted = Module::factory()->create(['name' => 'Subcutaan Injecteren']);
        $other = Module::factory()->create(['name' => 'Oogdruppels Toedienen']);

        $mine = ModuleActivation::factory()
            ->ofModule($wanted)->forOrganization($admin->organization)->create();
        ModuleActivation::factory()
            ->ofModule($other)->forOrganization($admin->organization)->create();

        // Lower case against a capitalised name, so the fold is exercised rather than assumed.
        $found = $this->listedIdentifiers('/nova-api/module-activations?search=subcutaan');

        $this->assertSame([(string) $mine->getKey()], $found);
    }

    #[Test]
    public function that_search_cannot_reach_another_organizations_row(): void
    {
        // Search runs on top of the scoped listing, so this is the assertion that says so.
        $admin = $this->signedInAdministrator();
        $module = Module::factory()->create(['name' => 'Subcutaan Injecteren']);

        ModuleActivation::factory()->ofModule($module)->create();

        $this->assertSame([], $this->listedIdentifiers('/nova-api/module-activations?search=subcutaan'));
        $this->assertNotNull($admin->fresh());
    }

    /**
     * The module's form as Nova posts it, with one list replaced.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function moduleForm(Module $module, array $overrides): array
    {
        return array_merge([
            'name' => $module->name,
            'category_id' => (string) $module->category_id,
            'description' => $module->description,
            'status' => ModuleStatus::Available->value,
            '_method' => 'PUT',
        ], $overrides);
    }

    /**
     * That many repeater rows, as Nova submits them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(int $count, string $type = 'module-video-repeatable'): array
    {
        $rows = [];

        for ($index = 0; $index < $count; $index++) {
            $rows[] = [
                // The key Nova derives from the repeatable's class name. A row whose type belongs
                // to a different repeater resolves to nothing and fails inside the field, which is
                // a broken request rather than a refused one.
                'type' => $type,
                'fields' => ['title' => 'Regel '.$index, 'url' => 'https://example.test/'.$index],
            ];
        }

        return $rows;
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

    private function signedInOperator(): User
    {
        return $this->signInThroughLink(
            User::factory()->platformAdministrator()->for(Organization::factory()->platform())->create(),
        );
    }

    private function signedInAdministrator(): User
    {
        return $this->signInThroughLink(
            User::factory()->administrator()->for(Organization::factory())->create(),
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
