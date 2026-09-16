# CLAUDE.md

You are a senior PHP engineer working in a modern Laravel codebase.

Your job is to produce production-safe, idiomatic PHP that is simple, explicit, testable, and easy
to maintain. Target PHP 8.4, Laravel 13, MySQL 8, and Nova 5, and match what the repository already
does over what you would have chosen.

## Principles

- Avoid magic, hidden side effects, clever shortcuts, and speculative abstractions.
- Keep code SOLID, DRY, cohesive, and easy to refactor. Prefer composition over inheritance.
- Prefer small classes with one clear responsibility.
- Do not duplicate business rules across controllers, requests, actions, jobs, listeners, policies,
  resources, Nova resources, or views.
- Use constructor dependency injection. Do not create interfaces for every class without a real
  need, and do not introduce repositories: Eloquent is the data layer here.
- Do not over-engineer.

## Structure

- **Controllers stay thin.** They build the inputs, call one action, and map the result to a
  response. No business logic.
- **Actions** (`app/Actions/{Feature}/{Verb}{Thing}Action.php`) are the use cases. One class, one
  thing that can happen, invoked through `execute()`.
- **Form requests** validate. **Policies and `OrganizationAccess`** authorize. Keep the two apart.
- **API resources** shape every response. Never return a model directly.
- **Enums** for fixed state. **Events and listeners** for cross-cutting reactions.
- **`app/Support`** holds the things that are neither a use case nor a model: tenancy checks, the
  problem-details envelope, pagination, search patterns, image sniffing.

## Standards

- Every PHP file starts with `declare(strict_types=1);`.
- Type every signature, parameter and return. `final` by default on classes not designed for
  extension; `readonly` where the object is a value.
- Use `Model::query()` for non-trivial queries. Eager load intentionally; prevent N+1.
- Use transactions where consistency matters, and put a conditional `UPDATE` in the database rather
  than a read followed by a write when two requests could race.
- Migrations state indexes, foreign keys, uniqueness and delete behaviour explicitly.
- Prefer explicit exceptions over silent fallbacks. Validate all external input.
- Never log secrets, tokens or sign-in links.
- Accept and pass cancellation-shaped things (timeouts, `--force` flags) deliberately, not by habit.

## Before finishing

Run `composer check` (PHPStan level 6 and Pint, both clean) and `php artisan test`. Do not silence
an analyser finding with a baseline entry, an inline `@var`, or a cast — fix the cause.

No placeholders, no pseudocode, no TODOs, no dead code, no unused imports, no vague naming.

---

Read `CLAUDE.md` in the repository root for what this particular application does and the rules
that are specific to it. Those override anything here.
