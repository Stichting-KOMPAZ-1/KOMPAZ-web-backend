<?php

declare(strict_types=1);

namespace App\Support\Modules;

use JsonException;

/**
 * The table in a "Tabel" block (KOM-101): column headers, and rows of plain text under them.
 *
 * The operator decides per table how many columns it has — one to ten — and every row has exactly
 * that many cells. A row does not have to be filled in completely, so a cell may be empty; a row
 * with nothing in it at all is not a row anybody meant, and is left out, as an untouched "+ rij"
 * would be. Cells are text as typed, never markup: a client shows them as written.
 *
 * {@see AcceptableTable} is what says whether something sent is a table; this is what one becomes
 * once it is, and what the column holds ({@see BlockTableCast}).
 */
final readonly class BlockTable
{
    public const int MAXIMUM_COLUMNS = 10;

    /** No form needs more, and a table longer than this is a spreadsheet, not learning content. */
    public const int MAXIMUM_ROWS = 100;

    public const int MAXIMUM_HEADER_LENGTH = 100;

    public const int MAXIMUM_CELL_LENGTH = 500;

    /**
     * @param  list<string>  $columns  the column headers, in order
     * @param  list<list<string>>  $rows  each as many cells as there are columns
     */
    public function __construct(
        public array $columns,
        public array $rows,
    ) {}

    /**
     * A table from what a form or a request sent: `{columns, rows}`, as an array or as the JSON the
     * panel's field sends. Null when nothing was sent, or nothing that could be a table.
     *
     * Every cell is trimmed, every row cut or padded to the number of columns, and rows with
     * nothing in them dropped — so whatever came in, what is kept has one shape.
     */
    public static function fromInput(mixed $input): ?self
    {
        $decoded = self::decode($input);

        if ($decoded === null || ! is_array($decoded['columns'] ?? null)) {
            return null;
        }

        $columns = array_values(array_map(self::text(...), $decoded['columns']));

        if ($columns === []) {
            return null;
        }

        $rows = [];

        foreach (is_array($decoded['rows'] ?? null) ? $decoded['rows'] : [] as $row) {
            $cells = array_values(array_map(self::text(...), is_array($row) ? $row : []));
            $cells = array_pad(array_slice($cells, 0, count($columns)), count($columns), '');

            if (implode('', $cells) !== '') {
                $rows[] = $cells;
            }
        }

        return new self($columns, $rows);
    }

    /**
     * The decoded `{columns, rows}`, or null when the input is not even that shape.
     *
     * @return array<array-key, mixed>|null
     */
    public static function decode(mixed $input): ?array
    {
        if (is_string($input)) {
            if (trim($input) === '') {
                return null;
            }

            try {
                $input = json_decode($input, true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }
        }

        return is_array($input) ? $input : null;
    }

    /** @return array{columns: list<string>, rows: list<list<string>>} */
    public function toArray(): array
    {
        return ['columns' => $this->columns, 'rows' => $this->rows];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
