<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Models\ELearning;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\ModuleCategory;
use App\Models\ModuleContact;
use App\Models\ModuleLink;
use App\Models\ModuleVideo;
use App\Models\Organization;
use App\Support\Files\StoredFile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the module tables guarantee on their own, without an action in front of them.
 *
 * Worth testing at this level because these are the rules the use cases will be written to rely
 * on: an action that deletes a module does not have to remember to unlink its courses, and one
 * that reads an organization's contacts does not have to prove they belong to that organization.
 * If the schema stops saying so, each of those becomes a silent bug rather than a failing test.
 */
final class ModuleSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function deleting_a_module_leaves_its_courses_standing(): void
    {
        // The promise made in the confirmation text an operator reads before deleting a module.
        $module = Module::factory()->create();
        $course = ELearning::factory()->create();
        $module->eLearnings()->attach($course);

        $module->delete();

        $this->assertModelExists($course);
        $this->assertDatabaseCount('e_learning_module', 0);
    }

    #[Test]
    public function deleting_a_course_leaves_its_modules_standing(): void
    {
        $module = Module::factory()->create();
        $course = ELearning::factory()->create();
        $module->eLearnings()->attach($course);

        $course->delete();

        $this->assertModelExists($module);
        $this->assertDatabaseCount('e_learning_module', 0);
    }

    #[Test]
    public function deleting_a_module_takes_everything_hanging_off_it(): void
    {
        $module = Module::factory()->create();
        $activation = ModuleActivation::factory()->ofModule($module)->create();
        ModuleVideo::factory()->ofModule($module)->create();
        ModuleLink::factory()->ofModule($module)->create();
        ModuleVideo::factory()->ofActivation($activation)->create();
        ModuleContact::factory()->ofActivation($activation)->create();

        $module->delete();

        // Including the organization's own additions, which reach the module through the
        // activation rather than directly.
        $this->assertDatabaseCount('module_activations', 0);
        $this->assertDatabaseCount('module_videos', 0);
        $this->assertDatabaseCount('module_links', 0);
        $this->assertDatabaseCount('module_contacts', 0);
    }

    #[Test]
    public function deleting_an_organization_takes_its_activations_and_leaves_the_module(): void
    {
        $organization = Organization::factory()->create();
        $module = Module::factory()->create();
        $activation = ModuleActivation::factory()->ofModule($module)->forOrganization($organization)->create();
        ModuleContact::factory()->ofActivation($activation)->create();

        $organization->delete();

        $this->assertModelExists($module);
        $this->assertDatabaseCount('module_activations', 0);
        $this->assertDatabaseCount('module_contacts', 0);
    }

    #[Test]
    public function a_module_can_only_be_switched_on_once_per_organization(): void
    {
        // Switching a module on twice is the same activation, not a second one — and a second row
        // would reset the date the organization administrator's table is ordered by.
        $organization = Organization::factory()->create();
        $module = Module::factory()->create();

        ModuleActivation::factory()->ofModule($module)->forOrganization($organization)->create();

        $this->expectException(QueryException::class);

        ModuleActivation::factory()->ofModule($module)->forOrganization($organization)->create();
    }

    #[Test]
    public function a_category_still_worn_by_a_module_cannot_be_deleted(): void
    {
        $category = ModuleCategory::factory()->create();
        Module::factory()->inCategory($category)->create();

        $this->expectException(QueryException::class);

        $category->delete();
    }

    #[Test]
    public function an_activation_knows_whether_its_contacts_have_been_filled_in(): void
    {
        // The one column the organization administrator's table exists to draw attention to.
        $activation = ModuleActivation::factory()->create();

        $this->assertFalse($activation->hasContactDetails());

        ModuleContact::factory()->ofActivation($activation)->create();

        $this->assertTrue($activation->fresh()?->hasContactDetails());
    }

    #[Test]
    public function a_module_may_have_no_picture_at_all(): void
    {
        $module = Module::factory()->withoutImage()->create();

        $this->assertNull($module->fresh()?->image());
    }

    #[Test]
    public function a_module_can_have_its_picture_taken_away_again(): void
    {
        // The edit form can change every field, and "no picture" is one of the values a picture
        // field can end up with.
        $module = Module::factory()->create();

        $module->clearImage();
        $module->save();

        $this->assertNull($module->fresh()?->image());
    }

    #[Test]
    public function half_a_picture_is_refused(): void
    {
        // Three columns that are only ever true together. A write that set the key and forgot the
        // media type would leave a row with a picture by one reading and none by another.
        $module = Module::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('modules')
            ->where('id', $module->getKey())
            ->update(['image_content_type' => null]);
    }

    #[Test]
    public function a_module_and_a_course_each_carry_a_picture_as_three_columns(): void
    {
        // The three are only ever true together, so they are read and written together. A caller
        // that set two of them would leave a row labelling somebody else's bytes.
        $module = Module::factory()->create();
        $replacement = new StoredFile('modules/x/image-2.webp', 'image/webp', 4096);

        $module->applyImage($replacement);
        $module->save();

        $stored = $module->fresh()?->image();

        $this->assertNotNull($stored);
        $this->assertSame('modules/x/image-2.webp', $stored->key);
        $this->assertSame('image/webp', $stored->contentType);
        $this->assertSame(4096, $stored->byteCount);

        $course = ELearning::factory()->create();
        $course->applyImage($replacement);
        $course->save();

        $this->assertSame('modules/x/image-2.webp', $course->fresh()?->image()->key);
    }

    #[Test]
    public function a_minted_key_names_the_record_it_belongs_to_and_is_never_the_same_twice(): void
    {
        // Nothing a caller says reaches another record's file, and replacing an image writes a new
        // one rather than overwriting the one still being served.
        $first = StoredFile::mintKey(Module::IMAGE_PREFIX, 'the-module', 'image', 'png');
        $second = StoredFile::mintKey(Module::IMAGE_PREFIX, 'the-module', 'image', 'png');

        $this->assertStringStartsWith('modules/the-module/image-', $first);
        $this->assertStringEndsWith('.png', $first);
        $this->assertNotSame($first, $second);
    }

    #[Test]
    public function a_module_finds_its_activation_for_one_organization_and_not_anothers(): void
    {
        $module = Module::factory()->create();
        $mine = Organization::factory()->create();
        $theirs = Organization::factory()->create();

        ModuleActivation::factory()->ofModule($module)->forOrganization($mine)->create();

        $this->assertNotNull($module->activationFor((string) $mine->getKey()));
        $this->assertNull($module->activationFor((string) $theirs->getKey()));
    }
}
