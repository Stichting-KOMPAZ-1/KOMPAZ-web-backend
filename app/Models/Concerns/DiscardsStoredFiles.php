<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Events\ContentFileDiscarded;

/**
 * Lets go of the bytes when a row stops pointing at them.
 *
 * On the model rather than in an action, and deliberately. Content is written through Nova's own
 * forms — rule 18's exception — so there is no one use case to hang this on: a delete from the
 * panel, from a test and from tinker are three different callers and all three owe the disk the
 * same thing. A model event is the only place all of them pass through.
 *
 * **The hard part is the cascade.** A foreign key removes rows without Eloquent ever seeing them,
 * so deleting a module takes its videos with it and nothing fires for any of them. That is what
 * `discardableKeys()` is for: a row that owns descendants holding files has to go and find their
 * keys *before* it is deleted, because afterwards nothing knows where the bytes were.
 *
 * Discarding the same key twice is harmless — the second delete finds nothing — which is what
 * makes it safe for a parent to claim its children's keys as well as its own.
 */
trait DiscardsStoredFiles
{
    public static function bootDiscardsStoredFiles(): void
    {
        static::deleting(function (self $model): void {
            foreach ($model->discardableKeys() as $key) {
                ContentFileDiscarded::dispatch($key);
            }
        });

        // A replacement is a discard too. The row is what decides which bytes are current, so the
        // moment it names a different key the old file is unreachable — and only this comparison
        // knows what it used to be.
        static::updating(function (self $model): void {
            foreach ($model->replacedKeys() as $key) {
                ContentFileDiscarded::dispatch($key);
            }
        });
    }

    /**
     * Every file that should go when this row does — its own, and any its cascades will destroy
     * without telling anyone.
     *
     * @return list<string>
     */
    abstract public function discardableKeys(): array;

    /**
     * The files this row pointed at a moment ago and no longer does.
     *
     * Read off the model's dirty state, so it is answered before the write rather than deduced
     * afterwards. A row whose file columns did not change answers with nothing.
     *
     * @return list<string>
     */
    public function replacedKeys(): array
    {
        $keys = [];

        foreach ($this->storedFileColumns() as $column) {
            if (! $this->isDirty($column)) {
                continue;
            }

            $previous = $this->getOriginal($column);

            if (is_string($previous) && $previous !== '') {
                $keys[] = $previous;
            }
        }

        return $keys;
    }

    /**
     * The columns on this model that hold a storage key.
     *
     * @return list<string>
     */
    abstract protected function storedFileColumns(): array;
}
