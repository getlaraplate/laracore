<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Database;

use Illuminate\Database\Eloquent\Model;

/**
 * A model on the {@see Db2PretendConnection} connection whose primary key, `code`, is not a Db2 reserved word,
 * so that a test can tell the key-name rule from the reserved-word rule.
 */
final class Db2CodeKeyStubModel extends Model
{
    protected $connection = 'db2_pretend';

    protected $table = 'db2_stub';

    protected $primaryKey = 'code';
}
