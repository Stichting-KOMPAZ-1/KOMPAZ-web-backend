<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Actions\Modules\SaveStepBlocksAction;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Whether what was sent for a "Tabel" block is a table this platform keeps (KOM-101).
 *
 * One to ten columns, each with a header; at most a hundred rows, none with more cells than there
 * are columns; and nothing longer than a header or a cell may be. A row with empty cells is fine —
 * the ticket says a row need not be filled in completely. Asked of the panel's field, which sends
 * JSON, and of the API's `table`, which is an object, so it reads both; a rule object for the
 * reason every rule here that answers in the product's words is one (rule 18).
 *
 * Absent is not this rule's to refuse: whether a table block must have a table is the use case's
 * question, because a block's kind decides it ({@see SaveStepBlocksAction}).
 */
final readonly class AcceptableTable implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $table = BlockTable::decode($value);
        $columns = $table['columns'] ?? null;
        $rows = $table['rows'] ?? [];

        if ($table === null || ! is_array($columns) || ! array_is_list($columns) || ! is_array($rows) || ! array_is_list($rows)) {
            $fail(ModuleMessages::TABLE_IS_MALFORMED);

            return;
        }

        $refusal = self::refusalOfColumns($columns) ?? self::refusalOfRows($rows, count($columns));

        if ($refusal !== null) {
            $fail($refusal);
        }
    }

    /** @param  list<mixed>  $columns */
    private static function refusalOfColumns(array $columns): ?string
    {
        if ($columns === []) {
            return ModuleMessages::TABLE_NEEDS_A_COLUMN;
        }

        if (count($columns) > BlockTable::MAXIMUM_COLUMNS) {
            return ModuleMessages::tableTooManyColumns();
        }

        foreach ($columns as $header) {
            if (! is_string($header) || trim($header) === '') {
                return ModuleMessages::TABLE_COLUMN_NEEDS_A_HEADER;
            }

            if (mb_strlen(trim($header)) > BlockTable::MAXIMUM_HEADER_LENGTH) {
                return ModuleMessages::tableHeaderTooLong();
            }
        }

        return null;
    }

    /** @param  list<mixed>  $rows */
    private static function refusalOfRows(array $rows, int $columns): ?string
    {
        if (count($rows) > BlockTable::MAXIMUM_ROWS) {
            return ModuleMessages::tableTooManyRows();
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ! array_is_list($row) || count($row) > $columns) {
                return ModuleMessages::TABLE_IS_MALFORMED;
            }

            foreach ($row as $cell) {
                if ($cell !== null && ! is_string($cell)) {
                    return ModuleMessages::TABLE_IS_MALFORMED;
                }

                if (is_string($cell) && mb_strlen(trim($cell)) > BlockTable::MAXIMUM_CELL_LENGTH) {
                    return ModuleMessages::tableCellTooLong();
                }
            }
        }

        return null;
    }
}
