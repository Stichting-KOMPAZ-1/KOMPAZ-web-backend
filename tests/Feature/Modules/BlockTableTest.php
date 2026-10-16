<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Support\Modules\AcceptableTable;
use App\Support\Modules\BlockTable;
use App\Support\Modules\ModuleMessages;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** A "Tabel" block's table (KOM-101): what is accepted, and the one shape it is kept in. */
final class BlockTableTest extends TestCase
{
    #[Test]
    public function every_row_gets_a_cell_per_column_and_an_empty_row_is_left_out(): void
    {
        $table = BlockTable::fromInput([
            'columns' => [' Moment ', 'Dosis', 'Opmerking'],
            'rows' => [
                ['Ochtend', ' 2 ml '],
                ['Middag', '1 ml', 'Na het eten', 'een cel te veel'],
                ['', ' ', null],
            ],
        ]);

        $this->assertSame([
            'columns' => ['Moment', 'Dosis', 'Opmerking'],
            'rows' => [['Ochtend', '2 ml', ''], ['Middag', '1 ml', 'Na het eten']],
        ], $table?->toArray());
    }

    #[Test]
    public function the_panels_json_is_read_the_same_as_the_apis_object(): void
    {
        $sent = ['columns' => ['Moment'], 'rows' => [['Ochtend']]];

        $this->assertEquals(BlockTable::fromInput($sent), BlockTable::fromInput((string) json_encode($sent)));
    }

    #[Test]
    public function nothing_is_no_table(): void
    {
        foreach ([null, '', '   ', 'geen json', ['rows' => [['x']]], ['columns' => []]] as $input) {
            $this->assertNull(BlockTable::fromInput($input));
        }
    }

    /** @return array<string, array{mixed, list<string>}> */
    public static function tables(): array
    {
        $headers = static fn (int $count): array => array_fill(0, $count, 'Kop');

        return [
            'one column' => [['columns' => ['Moment'], 'rows' => []], []],
            'ten columns' => [['columns' => $headers(10), 'rows' => []], []],
            'a row filled in partly' => [['columns' => ['Moment', 'Dosis'], 'rows' => [['Ochtend', '']]], []],
            'eleven columns' => [['columns' => $headers(11), 'rows' => []], [ModuleMessages::tableTooManyColumns()]],
            'no columns' => [['columns' => [], 'rows' => []], [ModuleMessages::TABLE_NEEDS_A_COLUMN]],
            'an empty header' => [['columns' => ['Moment', ''], 'rows' => []], [ModuleMessages::TABLE_COLUMN_NEEDS_A_HEADER]],
            'a row wider than the table' => [['columns' => ['Moment'], 'rows' => [['a', 'b']]], [ModuleMessages::TABLE_IS_MALFORMED]],
            'too many rows' => [['columns' => ['Moment'], 'rows' => array_fill(0, BlockTable::MAXIMUM_ROWS + 1, ['a'])], [ModuleMessages::tableTooManyRows()]],
            'a cell too long' => [['columns' => ['Moment'], 'rows' => [[str_repeat('a', BlockTable::MAXIMUM_CELL_LENGTH + 1)]]], [ModuleMessages::tableCellTooLong()]],
            'not a table' => ['geen json', [ModuleMessages::TABLE_IS_MALFORMED]],
        ];
    }

    /** @param  list<string>  $refusals */
    #[Test]
    #[DataProvider('tables')]
    public function a_table_is_judged_by_what_the_product_allows(mixed $table, array $refusals): void
    {
        $this->assertSame(
            $refusals,
            array_values(Validator::make(['table' => $table], ['table' => [new AcceptableTable]])->errors()->get('table')),
        );
    }
}
