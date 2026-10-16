<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Support\Modules\AcceptableCheckboxes;
use App\Support\Modules\BlockCheckboxes;
use App\Support\Modules\ModuleMessages;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** A "Checkboxes" block's options (KOM-107): what is accepted, and the one shape they are kept in. */
final class BlockCheckboxesTest extends TestCase
{
    #[Test]
    public function labels_are_trimmed_and_the_blank_ones_left_out(): void
    {
        $options = BlockCheckboxes::fromInput([
            ' Ik heb de bijsluiter gelezen ',
            '',
            'Ik weet wie ik kan bellen',
            '   ',
            null,
        ]);

        $this->assertSame(
            ['Ik heb de bijsluiter gelezen', 'Ik weet wie ik kan bellen'],
            $options?->toArray(),
        );
    }

    #[Test]
    public function the_panels_json_is_read_the_same_as_the_apis_array(): void
    {
        $sent = ['Eerste', 'Tweede'];

        $this->assertEquals(BlockCheckboxes::fromInput($sent), BlockCheckboxes::fromInput((string) json_encode($sent)));
    }

    /** Nothing, and nothing but blanks, are both "no options" — not an empty set of them. */
    #[Test]
    public function nothing_is_no_options(): void
    {
        foreach ([null, '', '   ', 'geen json', [], ['', '  '], ['columns' => ['x']]] as $input) {
            $this->assertNull(BlockCheckboxes::fromInput($input));
        }
    }

    /**
     * @param  array<int, string>  $refusals
     */
    #[Test]
    #[DataProvider('unacceptableOptions')]
    public function options_are_judged_by_what_the_product_allows(mixed $options, array $refusals): void
    {
        $validator = Validator::make(
            ['checkboxes' => $options],
            ['checkboxes' => ['nullable', new AcceptableCheckboxes]],
        );

        $this->assertSame($refusals, $validator->errors()->get('checkboxes'));
    }

    /** @return array<string, array{mixed, array<int, string>}> */
    public static function unacceptableOptions(): array
    {
        return [
            'a shape no form produces' => ['geen json', [ModuleMessages::CHECKBOXES_ARE_MALFORMED]],
            'an object rather than a list' => [['a' => 'Eerste'], [ModuleMessages::CHECKBOXES_ARE_MALFORMED]],
            'more than the maximum' => [
                array_fill(0, BlockCheckboxes::MAXIMUM_OPTIONS + 1, 'Optie'),
                [ModuleMessages::checkboxesTooMany()],
            ],
            'a label longer than allowed' => [
                [str_repeat('a', BlockCheckboxes::MAXIMUM_LABEL_LENGTH + 1)],
                [ModuleMessages::checkboxLabelTooLong()],
            ],
            'nothing but blanks' => [['', '   '], [ModuleMessages::CHECKBOXES_NEED_AN_OPTION]],
            // `ConvertEmptyStringsToNull` turns an option left empty into null before the rule sees
            // it, so null has to count as blank and be dropped — not read as a malformed option.
            'a blank the framework turned into null' => [['Eerste', null], []],
            'nothing but those nulls' => [[null, null], [ModuleMessages::CHECKBOXES_NEED_AN_OPTION]],
            'exactly the maximum' => [array_fill(0, BlockCheckboxes::MAXIMUM_OPTIONS, 'Optie'), []],
            'absent, which the use case refuses instead' => [null, []],
        ];
    }
}
