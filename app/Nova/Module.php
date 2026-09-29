<?php

declare(strict_types=1);

namespace App\Nova;

use App\Enums\ModuleStatus;
use App\Models\Module as ModuleModel;
use App\Models\ModuleCategory;
use App\Models\Organization as OrganizationModel;
use App\Models\User as UserModel;
use App\Support\Access\OrganizationAccess;
use App\Support\Files\StoredFile;
use App\Support\Images\AcceptableLogo;
use App\Support\Images\LogoImage;
use App\Support\Modules\LimitedList;
use App\Support\Modules\ModuleMessages;
use App\Support\Modules\ModuleReach;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Image;
use Laravel\Nova\Fields\Repeater;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Tag;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Support\Fluent;

/**
 * The modules the platform writes, and the form that writes them.
 *
 * **This is rule 18's exception, used on purpose.** Nova's own create, edit and delete are on here,
 * because none of the reasons they are off everywhere else apply: a module has no folded unique
 * column, nobody is emailed when one changes, and there is no `AdministratorCoverage` question
 * behind it. What is left is a form writing columns, which is what a form is for.
 *
 * The one thing that is *not* a form field is **Actief bij**. Which organizations have a module is
 * a rule rather than a column — an organization that already had it keeps the date it got it — so
 * it is {@see Actions\AssignModule} calling a use case, and this resource only shows the answer.
 *
 * Platform administrators only. An organization administrator reaches their own copy through
 * {@see ModuleActivation}, which is the same modules seen from the side that has a tenant.
 */
/**
 * @extends \App\Nova\Resource<ModuleModel>
 */
class Module extends Resource
{
    /** @var class-string<ModuleModel> */
    public static $model = ModuleModel::class;

    public static $title = 'name';

    /** @var array<int, string> */
    public static $search = ['name'];

    public static function label(): string
    {
        return 'Modules';
    }

    public static function singularLabel(): string
    {
        return 'Module';
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->onlyOnDetail(),

            Text::make('Naam', 'name')
                ->sortable()
                ->rules(['required', 'string', 'max:'.ModuleModel::MAXIMUM_NAME_LENGTH]),

            // Two fields over one column, the way Status is below it. A Select renders its stored
            // value on a table rather than its label, and the stored value here is a key — so the
            // reading half is its own field and the writing half is the picker.
            Text::make('Categorie', fn (): string => $this->model()->category->name)
                ->exceptOnForms(),

            // Picked from what exists and never created here: adding a category is a deploy, which
            // is the honest cost of the product leaving that out of this phase.
            Select::make('Categorie', 'category_id')
                ->options(ModuleCategory::options())
                ->displayUsingLabels()
                ->onlyOnForms()
                ->rules(['required', 'uuid']),

            // Optional: some modules have no picture. The bytes decide the media type, never the
            // upload's own header — see the store callback below.
            Image::make('Afbeelding', 'image_storage_key')
                ->disk(config('filesystems.default'))
                ->rules(['nullable', new AcceptableLogo])
                ->store($this->storeImage(...))
                ->preview(fn (): ?string => $this->model()->image() === null
                    ? null
                    : route('nova.module-image', ['module' => (string) $this->model()->getKey()]))
                ->prunable(false)
                ->deletable(true)
                ->delete(fn (): array => [
                    'image_storage_key' => null,
                    'image_content_type' => null,
                    'image_byte_count' => null,
                ]),

            Textarea::make('Omschrijving', 'description')
                ->alwaysShow()
                ->rules(['required', 'string']),

            Textarea::make('Bronvermelding', 'source_attribution')
                ->alwaysShow()
                ->rules(['nullable', 'string'])
                ->hideFromIndex(),

            Badge::make('Status', 'status')
                ->map([
                    ModuleStatus::Available->value => 'success',
                    ModuleStatus::InDevelopment->value => 'info',
                ])
                ->labels(ModuleStatus::options())
                ->exceptOnForms(),

            Select::make('Status', 'status')
                ->options(ModuleStatus::options())
                ->onlyOnForms()
                ->rules(['required', 'string', 'in:'.implode(',', ModuleStatus::values())]),

            // How far the module reaches, in the words the product chose. Computed from the counts
            // rather than stored, so a new organization takes a module back out of "Globaal" —
            // see {@see ModuleReach}.
            Text::make('Actief bij', fn (): string => ModuleReach::describe(
                (int) ($this->activations_count ?? 0),
                self::organizationCount(),
            ))->exceptOnForms(),

            // The courses this module shows. A plain link: attaching one changes nothing about the
            // course, and detaching one leaves it standing, which is what the deletion warning
            // promises an operator.
            Tag::make('E-learnings', 'eLearnings', ELearning::class)
                ->withPreview()
                ->hideFromIndex(),

            // "+" adds another entry, which is what the wireframe asks for. Videos here are the
            // platform's own; an organization's are on its activation.
            Repeater::make("Video's", 'videos')
                ->repeatables([Repeatables\ModuleVideoRepeatable::make()])
                ->asHasMany(ModuleVideo::class)
                // A count is not something a row can constrain, so the form is the only place
                // that can refuse an eleventh. In the product's words, not the framework's.
                ->rules(['array', new LimitedList(
                    ModuleMessages::maximumVideos(),
                    ModuleMessages::tooManyVideos(),
                )])
                ->hideFromIndex(),

            Repeater::make('Extra links', 'links')
                ->repeatables([Repeatables\ModuleLinkRepeatable::make()])
                ->asHasMany(ModuleLink::class)
                ->rules(['array', new LimitedList(
                    ModuleMessages::maximumLinks(),
                    ModuleMessages::tooManyLinks(),
                )])
                ->hideFromIndex(),
        ];
    }

    /**
     * Stores the upload and records what its bytes turned out to be.
     *
     * The media type is read out of the content rather than taken from the upload's `Content-Type`
     * or its file name, because the stored value is what a later response is labelled with —
     * believing the caller would let them choose how their bytes are handed back. The key is
     * minted from the module's identifier, never accepted from the form.
     *
     * @return array<string, mixed>
     */
    private function storeImage(Request $request, Model|Fluent $model, string $attribute, string $requestAttribute): array
    {
        $upload = $request->file($requestAttribute);

        if (! $upload instanceof UploadedFile) {
            return [];
        }

        $contents = (string) file_get_contents($upload->getRealPath());
        $contentType = LogoImage::detectContentType($contents);

        // Already refused by AcceptableLogo, which runs first and answers in Dutch under the field.
        // Reaching here with unrecognized bytes would be a bug rather than a rejected upload.
        if ($contentType === null) {
            return [];
        }

        $key = StoredFile::mintKey(
            ModuleModel::IMAGE_PREFIX,
            self::ownerIdentifier($model),
            'image',
            LogoImage::extensionFor($contentType),
        );

        Storage::put($key, $contents);

        // The bytes are written before the row that names them, so a failure between the two
        // leaves an orphan rather than a row pointing at nothing. Never the other way round.
        return [
            'image_storage_key' => $key,
            'image_content_type' => $contentType,
            'image_byte_count' => strlen($contents),
        ];
    }

    /**
     * The identifier the key is filed under.
     *
     * On an edit the module already has one. On a create it does not yet — Nova fills the fields
     * before the insert, and `HasUuids` mints the key during it — so one is minted here and set on
     * the model, which `HasUuids` then leaves alone. That keeps every file under `modules/{id}/`
     * rather than giving the first upload of a module's life a home of its own.
     */
    private static function ownerIdentifier(Model|Fluent $model): string
    {
        if ($model instanceof ModuleModel) {
            $existing = $model->getKey();

            if (is_string($existing) && $existing !== '') {
                return $existing;
            }

            $minted = (string) Str::orderedUuid();
            $model->setAttribute('id', $minted);

            return $minted;
        }

        return (string) Str::orderedUuid();
    }

    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        // Newest first, as the ticket asks. The key is a UUIDv7 so it breaks ties in the same
        // direction rather than arbitrarily.
        return $query
            ->with('category')
            ->withCount('activations')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /** How many organizations there are, which is what turns a count into "Globaal". */
    private static function organizationCount(): int
    {
        return OrganizationModel::query()->count();
    }

    /**
     * What an operator can do here beyond the form: decide who gets it.
     *
     * @return array<int, Action>
     */
    public function actions(NovaRequest $request): array
    {
        return [
            app(Actions\AssignModule::class)->sole()->showInline(),

            // Nova's own row delete is off for this resource, so this is the only way to remove a
            // module — and it is the only way to show the sentence the product wrote before
            // somebody confirms something that cannot be undone.
            app(Actions\DeleteModule::class)->sole()->showInline(),
        ];
    }

    /** Authoring a module is the platform's job, never a tenant's. */
    public static function authorizedToViewAny(Request $request): bool
    {
        return self::operatorIsPlatformAdministrator();
    }

    public static function authorizedToCreate(Request $request): bool
    {
        return self::operatorIsPlatformAdministrator();
    }

    public function authorizedToUpdate(Request $request): bool
    {
        return self::operatorIsPlatformAdministrator();
    }

    /**
     * Nova's own delete is off, which is the one place the content carve-out does not reach.
     *
     * Its confirmation modal carries a generic sentence and no resource can give it its own, and
     * the product wrote a specific one — it promises that the courses inside survive the module.
     * {@see Actions\DeleteModule} is where deleting a module lives instead.
     */
    public function authorizedToDelete(Request $request): bool
    {
        return false;
    }

    private static function operatorIsPlatformAdministrator(): bool
    {
        $operator = Auth::user();

        return $operator instanceof UserModel && OrganizationAccess::isPlatformAdministrator($operator);
    }
}
