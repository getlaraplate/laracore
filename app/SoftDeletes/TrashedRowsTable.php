<?php

declare(strict_types=1);

namespace Modules\Core\SoftDeletes;

use Illuminate\Database\Eloquent\Model;

/**
 * A table no model of the application reads any more, purged by {@see TrashedRowsPurger::purgeTable()} as a model with
 * no events of its own: its rows in the bin are simply deleted.
 */
final class TrashedRowsTable extends Model
{
    public $timestamps = false;

    public function __construct(string $table = '')
    {
        parent::__construct();

        $this->setTable($table);
    }
}
