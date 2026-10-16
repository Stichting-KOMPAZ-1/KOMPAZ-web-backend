---
name: nova-panel-investigation
description: Find out what Nova's panel actually shows or does before changing it — which PHP flag draws which control, how to measure the payload the browser receives, and why reading the Vue source alone gets it backwards. Use when a reviewer reports something about the panel UI (a missing button, a menu that vanished, a wrong label, a form that opens blank) or before adding, hiding or restyling any Nova action, button or column.
---

# Investigating the panel before changing it

The panel's behaviour comes from three layers, and the answer is usually not in the one you look at
first:

1. **The PHP resource and its actions** — `app/Nova/*.php`, `app/Nova/Actions/*.php`.
2. **Nova's own Vue**, in `vendor/laravel/nova/resources/js/` — reads the flags the PHP sets.
3. **This application's panel script**, `resources/nova/panel/src/` — globally re-registers some of
   Nova's components (`ActionDropdown`, `ResourceTable`). Built to `dist/js/panel.js` and committed.

A fourth layer used to exist — a stylesheet that restyled a control into something it was not. It is
gone, and that is the point: **a control may not be what the markup says it is.** Check layer 3, and
`git log`, before concluding anything from layer 1.

## Measure, do not infer

Reading a Vue component and reasoning about what it must do has been wrong more than once here. The
reliable move is a throwaway PHPUnit test that signs in and prints what Nova actually serves:

```php
// tests/Feature/Nova/TempProbeTest.php — write it, read it, DELETE it.
config(['session.driver' => 'database']);
$operator = User::factory()->platformAdministrator()
    ->for(Organization::factory()->platform())->create();

$secret = app(SecretTokenFactory::class)->create();
LoginToken::query()->create([
    'user_id' => $operator->getKey(),
    'token_hash' => $secret->hash,
    'purpose' => LoginTokenPurpose::MagicLink,
    'expires_at' => Carbon::now()->addMinutes(30),
]);

// The link is SPENT BY THE POST, not by opening it (KOM-75). A GET to
// `nova.sign-in.claim` only renders the confirm page and leaves you a guest —
// which shows up later as 401s that look like an authorization finding.
$this->post(route('nova.sign-in.redeem'), ['token' => $secret->value])
    ->assertRedirect(config('nova.path'));

$actions = $this->getJson('/nova-api/users/actions?resources[]='.$id.'&display=index')
    ->json('actions');
fwrite(STDERR, json_encode($actions));
```

Delete the probe when done. One left behind fails `composer check`: an `assertTrue(true)` trips
PHPStan's `method.alreadyNarrowedType`.

## Which flag draws which control

Verified against Nova 5's own components — do not re-derive these from the class names:

| PHP | Serialized as | What it draws |
| --- | --- | --- |
| `->showInline()` | `showOnTableRow` | **Fills the row's "…" menu.** `ResourceTableRow.availableActions` is `actions.filter(a => a.showOnTableRow)`, handed to `InlineActionDropdown`. With none set, `shouldShowActionDropdown` is false and **the row's "…" disappears** |
| `->standalone()` | `standalone` | Goes in the `ActionDropdown` **above** the table, whose default trigger is an ellipsis icon with no label |
| `authorizedToCreate()` | `authorizedToCreate` | Nova's own `CreateResourceButton` — the blue "X aanmaken" |
| `createButtonLabel()` / `updateButtonLabel()` | — | The **form's submit button**, not the row control |
| `$title` / `title()` | — | Every place Nova names a row. Absent, it falls back to the key, so headings read as UUIDs. A row with no name column of its own needs a `title()` method |

`showInline()` does **not** replace the menu with inline buttons. Getting this backwards led to
proposing the removal of the very thing keeping those menus alive.

## Gotchas that cost time

- **`authorizedToCreate($request)` needs a real authenticated user.** Called with a bare
  `new Illuminate\Http\Request`, it returns `false` for every resource, including the ones that
  genuinely allow creation. A tinker one-liner will quietly lie to you.
- **Nova types its query hooks against `Illuminate\Contracts\Database\Eloquent\Builder`, which is
  not generic.** `indexQuery`/`detailQuery` docblocks must carry no `Builder<Model>`; PHPStan rejects
  the generic. Where a collection's element type matters (`options()`), scope an Eloquent query
  instead of the contract-typed one.
- **The row's edit pencil cannot be relabelled per resource** — it reads the global `__('Edit')`.
  `Actions\EditResource::for()` is how this project puts edit into the "…" menu under its own words.
- **`resources/nova/panel/dist/js/panel.js` is committed and the deploy builds nothing.** Editing a
  source file without `npm ci && npm run production` in `resources/nova/panel` changes nothing in the
  panel and fails no test. `PanelScriptTest` asserts the built file still contains what the sources
  put there.
- **Prefer the framework's own component over transcribing its Tailwind classes.** Copying
  `hover:bg-primary-400` and `dark:text-gray-800` by hand reproduced them wrongly and broke the
  night theme. Rendering Nova's `Button` gets the appearance, and the label, for free.

## Before calling a ticket unimplemented

`git log --oneline -S '<symbol>' --all` and read the recent merge commits. Reviewer feedback here is
often fixed in a branch named after it (`fix/nova-ticket-feedback`) while the ticket still sits at
"Ready for testing" — the status tracks *her re-test*, not the code.
