<?php

declare(strict_types=1);

namespace App\Nova\Concerns;

use App\Exceptions\Contracts\ProvidesProblemDetail;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Http\Requests\NovaRequest;
use RuntimeException;
use Throwable;

/**
 * What every panel operation needs and none of them should restate.
 *
 * A Nova action here is a button on top of a use case in `app/Actions` — it collects the operator's
 * input and calls one, so the rule it enforces has exactly one implementation whoever performs it.
 * That leaves three things in common: who is acting, what they are acting on, and what an operator
 * should see when the use case refuses.
 */
trait RunsUseCase
{
    /**
     * Runs the use case, and turns the application's own refusals into the panel's error banner.
     *
     * The actions under `app/Actions` throw for every rule they enforce, and those exceptions
     * already carry the sentence the person who caused them should read.
     */
    private function attempt(Closure $useCase, string $success): ActionResponse
    {
        try {
            $useCase();
        } catch (Throwable $failure) {
            // Only the application's own refusals become a banner. Anything else is a defect, and
            // reporting a defect as a refusal would tell an operator that a rule stopped them when
            // in fact nothing did — so it is rethrown and answered as the error it is.
            if (! $failure instanceof ProvidesProblemDetail) {
                throw $failure;
            }

            return ActionResponse::danger($failure->getMessage());
        }

        return ActionResponse::message($success);
    }

    /** The operator behind the click, which every use case takes as the actor. */
    private function operator(): User
    {
        $operator = Auth::user();

        // The panel is unreachable without a session and the `viewNova` gate has already refused
        // everybody who is not a platform administrator, so this is not a state Nova can be in.
        if (! $operator instanceof User) {
            throw new RuntimeException('A Nova action ran without an authenticated operator.');
        }

        return $operator;
    }

    /**
     * The record whose form is being built, when Nova knows which one that is.
     *
     * `handle()` is handed the selection, but `fields()` is built from a plain request, so a form
     * that should open on the current values has to look them up — and Nova names the selection
     * differently depending on where the operator clicked. The detail page sends `resourceId`; the
     * index sends `resources`, the same list `handle()` would be given. Reading only the first
     * meant every form opened from the index came up blank, which looks like a create form for
     * something that is an edit (KOM-23).
     *
     * A selection of more than one, or the literal `all`, prefills nothing: there is no single set
     * of current values to show, and every action here is `sole()` or `standalone()` anyway. The
     * required rules on those fields still hold, so an empty form cannot blank a column.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel|null
     */
    private function selected(NovaRequest $request, string $model): ?Model
    {
        $key = $this->selectedKey($request);

        if ($key === null) {
            return null;
        }

        return $model::query()->whereKey($key)->first();
    }

    /** The one identifier in the request, under whichever name this page uses for it. */
    private function selectedKey(NovaRequest $request): ?string
    {
        $key = $request->query('resourceId');

        if (is_string($key) && $key !== '') {
            return $key;
        }

        $selection = $request->query('resources');

        if (is_string($selection)) {
            // A comma-separated list, which is how the index sends more than one.
            $selection = explode(',', $selection);
        }

        if (! is_array($selection) || count($selection) !== 1) {
            return null;
        }

        $only = reset($selection);

        return is_string($only) && $only !== '' && $only !== 'all' ? $only : null;
    }

    /**
     * The one record a `sole()` action was run against.
     *
     * Every action here is `sole()` or `standalone()` for the same reason the API takes one
     * resource per call: a rule that refuses halfway through a selection would leave an operator
     * guessing which half it applied to.
     *
     * @template TModel of Model
     *
     * @param  Collection<int, Model>  $models
     * @param  class-string<TModel>  $expected
     * @return TModel
     */
    private function target(Collection $models, string $expected): Model
    {
        $target = $models->first();

        if (! $target instanceof $expected) {
            throw new RuntimeException(
                sprintf('A Nova action expected exactly one %s to act on.', class_basename($expected)),
            );
        }

        return $target;
    }
}
