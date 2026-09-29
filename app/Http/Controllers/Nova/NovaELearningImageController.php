<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nova;

use App\Models\ELearning;
use App\Support\Files\ServedFile;

/**
 * A course's picture, for the panel's own pages.
 *
 * The API's image endpoint is unreachable from here for the reason the logo's is: an `<img src>`
 * on a Nova page sends the session cookie and cannot send a bearer token. Same bytes, same
 * headers, a different guard — a session and the `viewNova` gate on the route.
 */
final readonly class NovaELearningImageController
{
    public function show(ELearning $eLearning): ServedFile
    {
        return ServedFile::for($eLearning->image());
    }
}
