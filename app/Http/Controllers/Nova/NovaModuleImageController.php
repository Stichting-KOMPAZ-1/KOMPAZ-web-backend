<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nova;

use App\Exceptions\NotFoundException;
use App\Models\Module;
use App\Support\Files\ServedFile;

/**
 * A module's picture, for the panel's own pages.
 *
 * The API's image endpoint is unreachable from here for the reason the logo's is: an `<img src>`
 * on a Nova page sends the session cookie and cannot send a bearer token. Same bytes, same
 * headers, a different guard — a session and the `viewNova` gate, which is where every other panel
 * route's check is.
 *
 * Unlike a logo there is no placeholder. A module without a picture simply has none, so this
 * answers that it is not there rather than inventing something to show.
 */
final readonly class NovaModuleImageController
{
    public function show(Module $module): ServedFile
    {
        $image = $module->image();

        if ($image === null) {
            throw new NotFoundException('Deze module heeft geen afbeelding.');
        }

        return ServedFile::for($image);
    }
}
