<?php

declare(strict_types=1);

namespace Bridge\Stream\Concerns;

use Bridge\Stream\WatchChanges;
use Illuminate\Database\Eloquent\Model;

/**
 * Publishes this model's changes to watched props (PLAN §20.6): created,
 * updated, deleted and restored records reach every page watching the model
 * or the record. Define `streamOn(): array` to choose the channels (for
 * example the tenant's); without it `bridge.watch.channels` is used, which
 * every subscriber of that channel receives.
 *
 * @mixin Model
 */
trait StreamsChanges
{
    public static function bootStreamsChanges(): void
    {
        $record = static function (Model $model): void {
            app(WatchChanges::class)->modelChanged($model);
        };

        static::created($record);
        static::updated($record);
        static::deleted($record);

        // Soft deletes.
        if (method_exists(static::class, 'restored')) {
            static::restored($record);
        }

        if (method_exists(static::class, 'forceDeleted')) {
            static::forceDeleted($record);
        }
    }
}
