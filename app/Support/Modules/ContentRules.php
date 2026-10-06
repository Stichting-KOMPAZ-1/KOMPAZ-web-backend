<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Enums\ModuleStatus;
use App\Models\Chapter;
use App\Models\ContentBlock;
use App\Models\ELearning;
use App\Models\Module;
use App\Models\ModuleContact;
use App\Models\ModuleLink;
use App\Models\ModuleVideo;
use App\Models\Step;
use App\Support\Html\NonEmptyHtml;
use App\Support\Html\SanitizedHtml;
use App\Support\Images\AcceptableLogo;
use App\Support\Links\AcceptableWebAddress;
use App\Support\Links\WebAddress;
use Illuminate\Validation\Rules\Enum;

/**
 * What each field of the platform's content accepts, said once for the two places that write it.
 *
 * Content is written in the panel's forms and through the API, and a rule stated separately in
 * each would be two rules the day somebody changes one of them. Both ask here: a Nova field passes
 * one of these to `rules()`, a form request puts it under its key. What neither can hold — a count,
 * a picture's format — is a rule object ({@see LimitedList}, {@see AcceptableLogo}) for the reason
 * given there, and appears here as one.
 */
final class ContentRules
{
    /** @return list<mixed> */
    public static function moduleName(): array
    {
        return ['required', 'string', 'max:'.Module::MAXIMUM_NAME_LENGTH];
    }

    /** @return list<mixed> */
    public static function moduleCategory(): array
    {
        return ['required', 'uuid', 'exists:module_categories,id'];
    }

    /**
     * A module's description. Plain text, unlike a chapter's: the product never asked for
     * markup here, and a client shows it as written.
     *
     * @return list<mixed>
     */
    public static function moduleDescription(): array
    {
        return ['required', 'string'];
    }

    /**
     * Where a module's content came from. Plain text, like the description, and may be left out.
     *
     * @return list<mixed>
     */
    public static function sourceAttribution(): array
    {
        return ['nullable', 'string'];
    }

    /** @return list<mixed> */
    public static function moduleStatus(): array
    {
        return ['required', 'string', new Enum(ModuleStatus::class)];
    }

    /**
     * A picture where one may be left out, or kept as it is.
     *
     * @return list<mixed>
     */
    public static function optionalImage(): array
    {
        return ['nullable', 'file', new AcceptableLogo];
    }

    /**
     * A picture that has to be there.
     *
     * @return list<mixed>
     */
    public static function requiredImage(): array
    {
        return ['required', 'file', new AcceptableLogo];
    }

    /** @return list<mixed> */
    public static function videoTitle(): array
    {
        return ['required', 'string', 'max:'.ModuleVideo::MAXIMUM_TITLE_LENGTH];
    }

    /** @return list<mixed> */
    public static function linkTitle(): array
    {
        return ['required', 'string', 'max:'.ModuleLink::MAXIMUM_TITLE_LENGTH];
    }

    /**
     * A link's address, which is also a linked video's. `www.voorbeeld.nl` is accepted and stored
     * with `https://` in front ({@see WebAddress}).
     *
     * @return list<mixed>
     */
    public static function url(): array
    {
        return ['required', 'string', new AcceptableWebAddress];
    }

    /**
     * A video's link, which may be left out for an upload instead.
     *
     * @return list<mixed>
     */
    public static function optionalUrl(): array
    {
        return ['nullable', 'string', new AcceptableWebAddress];
    }

    /**
     * A finished upload a video is to show. Whose it is and whether it is finished is the claim's.
     *
     * @return list<mixed>
     */
    public static function videoUploadId(): array
    {
        return ['nullable', 'uuid'];
    }

    /** @return list<mixed> */
    public static function contactName(): array
    {
        return ['required', 'string', 'max:'.ModuleContact::MAXIMUM_NAME_LENGTH];
    }

    /** @return list<mixed> */
    public static function contactJobRole(): array
    {
        return ['required', 'string', 'max:'.ModuleContact::MAXIMUM_JOB_ROLE_LENGTH];
    }

    /** @return list<mixed> */
    public static function contactEmail(): array
    {
        return ['required', 'email', 'max:320'];
    }

    /** @return list<mixed> */
    public static function contactPhone(): array
    {
        return ['nullable', 'string', 'max:50'];
    }

    /**
     * The reason to get in touch, and when somebody is available: both free prose.
     *
     * @return list<mixed>
     */
    public static function contactNote(): array
    {
        return ['nullable', 'string'];
    }

    /** @return list<mixed> */
    public static function eLearningName(): array
    {
        return ['required', 'string', 'max:'.ELearning::MAXIMUM_NAME_LENGTH];
    }

    /** @return list<mixed> */
    public static function chapterName(): array
    {
        return ['required', 'string', 'max:'.Chapter::MAXIMUM_NAME_LENGTH];
    }

    /** @return list<mixed> */
    public static function chapterDescription(): array
    {
        return ['nullable', 'string'];
    }

    /** @return list<mixed> */
    public static function stepName(): array
    {
        return ['required', 'string', 'max:'.Step::MAXIMUM_NAME_LENGTH];
    }

    /**
     * Every kind of block's optional heading or caption.
     *
     * @return list<mixed>
     */
    public static function blockTitle(): array
    {
        return ['nullable', 'string', 'max:'.ContentBlock::MAXIMUM_TITLE_LENGTH];
    }

    /**
     * A text block's body: the markup the panel's editor produces, and what the API is given
     * under the same name.
     *
     * @return list<mixed>
     */
    public static function blockBody(): array
    {
        return ['required', ...self::markup(ModuleMessages::BLOCK_NEEDS_BODY)];
    }

    /**
     * A prose field that holds markup and may not end up blank.
     *
     * `required` is asked of what was sent and {@see SanitizedHtml} decides what the row keeps,
     * so a field that may not be null needs both: see {@see NonEmptyHtml} for what sits between
     * them. Composed rather than written out at each site so the two doors cannot drift.
     *
     * @return list<mixed>
     */
    public static function markup(string $whenBlank): array
    {
        return ['string', new NonEmptyHtml($whenBlank)];
    }

    /** @return list<mixed> */
    public static function videoList(): array
    {
        return ['array', new LimitedList(ModuleMessages::maximumVideos(), ModuleMessages::tooManyVideos())];
    }

    /** @return list<mixed> */
    public static function linkList(): array
    {
        return ['array', new LimitedList(ModuleMessages::maximumLinks(), ModuleMessages::tooManyLinks())];
    }

    /** @return list<mixed> */
    public static function contactList(): array
    {
        return ['array', new LimitedList(ModuleMessages::maximumContacts(), ModuleMessages::tooManyContacts())];
    }

    /**
     * A step's blocks: at least one, because the blocks are what a step is.
     *
     * @return list<mixed>
     */
    public static function blockList(): array
    {
        return [new NonEmptyList(ModuleMessages::STEP_NEEDS_A_BLOCK), 'array'];
    }
}
