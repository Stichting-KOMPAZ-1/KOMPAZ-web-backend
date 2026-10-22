<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A checkbox block's options become `{id, label}` rather than bare labels.
 *
 * A client keeps which boxes a reader ticked on the reader's device, and needs something to key
 * them by that survives the operator rewording an option or putting them in another order — which
 * neither the text nor the position does. Every option already written is given its identifier
 * here, in the order it is shown; the check constraint counts the list and so holds either shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite(static fn (mixed $label): array => [
            'id' => (string) Str::uuid7(),
            'label' => is_scalar($label) ? (string) $label : '',
        ]);
    }

    public function down(): void
    {
        $this->rewrite(static fn (mixed $option): string => is_array($option) && is_scalar($option['label'] ?? null)
            ? (string) $option['label']
            : '');
    }

    /** @param  Closure(mixed): (array{id: string, label: string}|string)  $convert */
    private function rewrite(Closure $convert): void
    {
        DB::transaction(function () use ($convert): void {
            $blocks = DB::table('content_blocks')
                ->where('type', 'Checkboxes')
                ->whereNotNull('checkbox_options')
                ->get(['id', 'checkbox_options']);

            foreach ($blocks as $block) {
                $options = json_decode((string) $block->checkbox_options, true, 512, JSON_THROW_ON_ERROR);

                DB::table('content_blocks')->where('id', $block->id)->update([
                    'checkbox_options' => json_encode(
                        array_map($convert, is_array($options) ? $options : []),
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                    ),
                ]);
            }
        });
    }
};
