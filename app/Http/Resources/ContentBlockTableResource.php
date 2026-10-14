<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Modules\BlockTable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The table of a "Tabel" block (KOM-101): its column headers, and its rows of plain text.
 *
 * Every row has exactly as many cells as there are columns, an empty cell being an empty string.
 * Text as typed, never markup: a client shows it as written, and lets the table scroll sideways
 * when it is wider than the screen.
 *
 * @mixin BlockTable
 */
final class ContentBlockTableResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var list<string> */
            'columns' => $this->columns,
            /** @var list<list<string>> */
            'rows' => $this->rows,
        ];
    }
}
