<?php

declare(strict_types=1);

namespace App\Nova\Concerns;

use App\Exceptions\Contracts\ProvidesProblemDetail;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
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
     * Runs the use case, and turns the application's own refusals into something the operator can
     * read.
     *
     * The actions under `app/Actions` throw for every rule they enforce, and those exceptions
     * already carry the sentence the person who caused them should read.
     *
     * Naming `$refusalField` says the refusal is about something the operator typed. It then
     * arrives as a validation error on that field instead of as a banner, which is the difference
     * between Nova leaving the dialog open with the sentence under the input and Nova closing it —
     * and a closed dialog means retyping a form to fix one word. Leave it null where the refusal
     * is about the record rather than the form, which is most of them.
     */
    private function attempt(Closure $useCase, string $success, ?string $refusalField = null): ActionResponse
    {
        try {
            $useCase();
        } catch (Throwable $failure) {
            // Only the application's own refusals are answered. Anything else is a defect, and
            // reporting a defect as a refusal would tell an operator that a rule stopped them when
            // in fact nothing did — so it is rethrown and answered as the error it is.
            if (! $failure instanceof ProvidesProblemDetail) {
                throw $failure;
            }

            if ($refusalField !== null) {
                throw ValidationException::withMessages([$refusalField => $failure->getMessage()]);
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
     * that should open on the current values has to look them up. On the index, where no row is
     * selected yet, there is nothing to prefill and the form opens empty — the required rules on
     * those fields still hold, so an operator cannot blank a column by leaving it alone.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel|null
     */
    private function selected(NovaRequest $request, string $model): ?Model
    {
        $key = $request->query('resourceId');

        if (! is_string($key) || $key === '') {
            return null;
        }

        return $model::query()->whereKey($key)->first();
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
