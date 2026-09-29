<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nova;

use App\Actions\Modules\ReorderContentAction;
use App\Exceptions\Contracts\ProvidesProblemDetail;
use App\Exceptions\NotFoundException;
use App\Models\Chapter;
use App\Models\ELearning;
use App\Models\User;
use App\Nova\Chapter as ChapterResource;
use App\Nova\ELearning as ELearningResource;
use App\Nova\Step as StepResource;
use Closure;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * The three addresses the drag-and-drop in the panel posts to, answered by us rather than by the
 * package that draws it.
 *
 * `outl1ne/nova-sortable` ships its own controller for these, and it cannot be used here: its
 * routes carry no authentication at all, and it resolves the parent from the request's resource
 * name and calls whatever method the request names as the relationship — `delete` included — on
 * a record looked up by the request's key. Its provider is left undiscovered (`dont-discover` in
 * composer.json); its script and stylesheet are registered in the Nova service provider, and these
 * routes take the same paths behind Nova's own authentication.
 *
 * What can be reordered is a closed list: a course's chapters and a chapter's steps. Anything else
 * the browser names is not found.
 */
final readonly class NovaReorderController
{
    public function __construct(private ReorderContentAction $reorder) {}

    public function updateOrder(Request $request, string $resource): Response
    {
        $request->validate([
            'resourceIds' => ['required', 'array', 'list'],
            'resourceIds.*' => ['required', 'string'],
        ]);

        $ids = array_values(array_filter($request->array('resourceIds'), 'is_string'));

        return $this->answering(fn () => $this->reorder->execute(
            $this->operator($request),
            $this->children($request, $resource),
            $ids,
        ));
    }

    public function moveToStart(Request $request, string $resource): Response
    {
        return $this->moveTo($request, $resource, atStart: true);
    }

    public function moveToEnd(Request $request, string $resource): Response
    {
        return $this->moveTo($request, $resource, atStart: false);
    }

    private function moveTo(Request $request, string $resource, bool $atStart): Response
    {
        return $this->answering(function () use ($request, $resource, $atStart): void {
            $moving = $request->string('resourceId')->toString();

            $others = array_values(array_filter(
                $this->children($request, $resource)->pluck('id')->all(),
                static fn (mixed $id): bool => is_string($id) && $id !== $moving,
            ));

            $order = $atStart ? [$moving, ...$others] : [...$others, $moving];

            $this->reorder->execute($this->operator($request), $this->children($request, $resource), $order);
        });
    }

    /**
     * Runs the reorder, and turns a refusal into the status it means.
     *
     * These paths are the panel's, which keeps Laravel's own error shapes rather than the API's
     * problem details (see `bootstrap/app.php`), so a refusal left alone would reach the browser
     * as a 500 — a rule reported as a crash.
     */
    private function answering(Closure $reorder): Response
    {
        try {
            $reorder();
        } catch (Throwable $exception) {
            // Anything else is a defect, and reporting it as a refusal would hide it.
            if (! $exception instanceof ProvidesProblemDetail) {
                throw $exception;
            }

            abort($exception->problemStatus(), $exception->getMessage());
        }

        return response()->noContent();
    }

    /**
     * The list a request is about, under the parent it names — from a closed set of two.
     *
     * @return HasMany<covariant \Illuminate\Database\Eloquent\Model, covariant \Illuminate\Database\Eloquent\Model>
     */
    private function children(Request $request, string $resource): HasMany
    {
        $via = $request->string('viaResource')->toString();
        $relationship = $request->string('viaRelationship')->toString();
        $parentId = $request->string('viaResourceId')->toString();

        if ($resource === ChapterResource::uriKey() && $via === ELearningResource::uriKey() && $relationship === 'chapters') {
            return ELearning::query()->findOrFail($parentId)->chapters();
        }

        if ($resource === StepResource::uriKey() && $via === ChapterResource::uriKey() && $relationship === 'steps') {
            return Chapter::query()->findOrFail($parentId)->steps();
        }

        throw new NotFoundException('Deze lijst kan niet op volgorde worden gezet.');
    }

    private function operator(Request $request): User
    {
        $operator = $request->user();

        if (! $operator instanceof User) {
            abort(HttpResponse::HTTP_UNAUTHORIZED);
        }

        return $operator;
    }
}
