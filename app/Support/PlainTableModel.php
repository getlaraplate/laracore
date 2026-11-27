<?php

declare(strict_types=1);

namespace Modules\Core\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A table known only by its name, for a maintenance write on a table no model of the application reads any more
 * ({@see \Modules\Core\SoftDeletes\TrashedRowsPurger}, {@see \Modules\Core\Locking\LockedRowsReleaser}): a model with no
 * events or scopes of its own, on the default connection.
 */
final class PlainTableModel extends Model
{
    public $timestamps = false;

    public function __construct(string $table = '')
    {
        parent::__construct();

        $this->setTable($table);
    }
}
