<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Database;

use Illuminate\Database\Eloquent\Model;

/**
 * A model on the private connection name {@see Db2PretendConnection::capture()} registers, so that a test
 * can have a model whose connection is Db2 without a server.
 */
final class Db2ConnectionStubModel extends Model
{
    protected $connection = 'db2_pretend';

    protected $table = 'db2_stub';
}
