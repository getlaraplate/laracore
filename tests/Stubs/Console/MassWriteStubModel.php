<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Console;

use Illuminate\Database\Eloquent\Model;

/**
 * A model the settings commands (`model:locked-*`, `model:optimistic-lock-*`, `model:soft-deletes-remove`) run on in
 * the mass write flush tests. It has no table and no trait to remove.
 */
final class MassWriteStubModel extends Model
{
    protected $table = 'mass_write_stub';
}
