<?php

declare(strict_types=1);

namespace Bridge\Tests\Fixtures\Models;

use Bridge\Stream\Concerns\StreamsChanges;
use Illuminate\Database\Eloquent\Model;

/**
 * A model without streamOn(): its changes go to `bridge.watch.channels`.
 *
 * @property int $id
 * @property string $body
 */
final class WatchedNote extends Model
{
    use StreamsChanges;

    protected $table = 'notes';

    protected $guarded = [];
}
