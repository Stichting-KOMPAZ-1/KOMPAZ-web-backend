<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Enums\LoginTokenPurpose;
use App\Enums\UserRole;
use App\Models\LoginToken;
use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Organization;
use App\Models\User;
use App\Nova\Actions\CreateModuleCategory;
use App\Nova\Actions\DeleteModuleCategory;
use App\Nova\Actions\RenameModuleCategory;
use App\Services\SecretTokenFactory;
use App\Support\Modules\ModuleMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Managing the categories a module is filed under (KOM-51), in the panel and through the API.
 *
 * Two rules carry it: a name is unique folded, and a category a module still wears cannot go.
 * Both are the use cases', so both doors are tested against them.
 */
final class ModuleCategoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_platform_creates_a_category_and_a_name_taken_in_any_case_is_a_conflict(): void
    {
        $headers = $this->tokenHeaders($this->platformAdministrator());

        $this->withHeaders($headers)
            ->postJson('/api/module-categories', ['name' => '  Wondzorg '])
            ->assertCreated()
            ->assertJsonPath('name', 'Wondzorg');

        $this->withHeaders($headers)
            ->postJson('/api/module-categories', ['name' => 'WONDZORG'])
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertJsonPath('detail', ModuleMessages::CATEGORY_NAME_TAKEN);

        $this->withHeaders($headers)
            ->postJson('/api/module-categories', ['name' => ' '])
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('errors.name.0', ModuleMessages::CATEGORY_NAME_REQUIRED);

        $this->assertSame(1, ModuleCategory::query()->count());
    }

    #[Test]
    public function a_category_is_renamed_and_may_keep_its_own_name_in_another_case(): void
    {
        $headers = $this->tokenHeaders($this->platformAdministrator());
        $category = ModuleCategory::factory()->named('Medicatie')->create();
        ModuleCategory::factory()->named('Revalidatie')->create();

        $this->withHeaders($headers)
            ->putJson("/api/module-categories/{$category->getKey()}", ['name' => 'MEDICATIE'])
            ->assertOk()
            ->assertJsonPath('name', 'MEDICATIE');

        $this->withHeaders($headers)
            ->putJson("/api/module-categories/{$category->getKey()}", ['name' => 'revalidatie'])
            ->assertStatus(Response::HTTP_CONFLICT);
    }

    #[Test]
    public function a_category_a_module_still_wears_cannot_be_deleted(): void
    {
        $headers = $this->tokenHeaders($this->platformAdministrator());
        $worn = ModuleCategory::factory()->create();
        Module::factory()->count(2)->create(['category_id' => $worn->getKey()]);
        $unused = ModuleCategory::factory()->create();

        $this->withHeaders($headers)
            ->deleteJson("/api/module-categories/{$worn->getKey()}")
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertJsonPath('detail', ModuleMessages::categoryInUse(2));

        $this->withHeaders($headers)
            ->deleteJson("/api/module-categories/{$unused->getKey()}")
            ->assertNoContent();

        $this->assertModelExists($worn);
        $this->assertModelMissing($unused);
    }

    #[Test]
    public function only_the_platform_writes_categories_and_everybody_reads_them(): void
    {
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $category = ModuleCategory::factory()->named('Medicatie')->create();
        $headers = $this->tokenHeaders($administrator);

        $this->withHeaders($headers)->getJson('/api/module-categories')->assertOk()->assertJsonPath('items.0.name', 'Medicatie');
        $this->withHeaders($headers)->postJson('/api/module-categories', ['name' => 'Nieuw'])->assertForbidden();
        $this->withHeaders($headers)->putJson("/api/module-categories/{$category->getKey()}", ['name' => 'Anders'])->assertForbidden();
        $this->withHeaders($headers)->deleteJson("/api/module-categories/{$category->getKey()}")->assertForbidden();

        $this->assertSame('Medicatie', $category->fresh()?->name);
    }

    #[Test]
    public function a_category_created_through_a_request_says_who_created_it(): void
    {
        $operator = $this->platformAdministrator();

        $this->withHeaders($this->tokenHeaders($operator))
            ->postJson('/api/module-categories', ['name' => 'Wondzorg'])
            ->assertCreated();

        $this->assertSame($operator->getKey(), ModuleCategory::query()->sole()->created_by);
    }

    #[Test]
    public function the_panel_creates_renames_and_deletes_through_its_dialogs(): void
    {
        $this->signedInOperator();

        $this->runAction(CreateModuleCategory::class, ['name' => 'Wondzorg'])->assertOk();
        $category = ModuleCategory::query()->where('name', 'Wondzorg')->sole();

        $this->runAction(RenameModuleCategory::class, ['resources' => (string) $category->getKey(), 'name' => 'Wondverzorging'])
            ->assertOk();
        $this->assertSame('Wondverzorging', $category->fresh()?->name);

        $this->runAction(DeleteModuleCategory::class, ['resources' => (string) $category->getKey()])->assertOk();
        $this->assertModelMissing($category);
    }

    #[Test]
    public function the_panel_answers_a_taken_name_under_the_field_and_a_worn_category_as_a_banner(): void
    {
        // Under the field, so the dialog stays open on the word to change (rule 18's refusalField).
        $this->signedInOperator();
        ModuleCategory::factory()->named('Medicatie')->create();
        $worn = ModuleCategory::factory()->create();
        Module::factory()->create(['category_id' => $worn->getKey()]);

        $this->runAction(CreateModuleCategory::class, ['name' => 'medicatie'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('errors.name.0', ModuleMessages::CATEGORY_NAME_TAKEN);

        $this->runAction(DeleteModuleCategory::class, ['resources' => (string) $worn->getKey()])
            ->assertOk()
            ->assertJsonPath('danger', ModuleMessages::categoryInUse(1));

        $this->assertModelExists($worn);
    }

    #[Test]
    public function novas_own_form_is_refused_because_it_would_skip_the_folded_name(): void
    {
        $this->signedInOperator();
        $category = ModuleCategory::factory()->create();

        $this->postJson('/nova-api/module-categories', ['name' => 'Rechtstreeks'])->assertForbidden();
        $this->putJson("/nova-api/module-categories/{$category->getKey()}", ['name' => 'Rechtstreeks'])->assertForbidden();

        $this->assertDatabaseMissing('module_categories', ['name' => 'Rechtstreeks']);
    }

    /**
     * @param  class-string  $action
     * @param  array<string, mixed>  $payload
     * @return TestResponse<\Illuminate\Http\Response>
     */
    private function runAction(string $action, array $payload): TestResponse
    {
        return $this->post(
            sprintf('/nova-api/module-categories/action?action=%s', app($action)->uriKey()),
            $payload,
            ['Accept' => 'application/json'],
        );
    }

    private function platformAdministrator(): User
    {
        return User::factory()->create([
            'organization_id' => Organization::factory()->platform()->create()->getKey(),
            'role' => UserRole::PlatformAdministrator,
        ]);
    }

    private function signedInOperator(): User
    {
        $user = $this->platformAdministrator();

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
