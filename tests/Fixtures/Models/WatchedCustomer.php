<?php

declare(strict_types=1);

namespace Bridge\Tests\Fixtures\Models;

use Bridge\Stream\Concerns\StreamsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A model publishing its changes on its tenant's channel.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 */
class WatchedCustomer extends Model
{
    use SoftDeletes;
    use StreamsChanges;

    protected $table = 'customers';

    protected $guarded = [];

    /** @return list<string> */
    public function streamOn(): array
    {
        return ["tenant.{$this->tenant_id}"];
    }
}
