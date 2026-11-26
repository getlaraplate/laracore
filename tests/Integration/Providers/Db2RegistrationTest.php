<?php

declare(strict_types=1);

use Easi\DB2\DB2ServiceProvider;
use Illuminate\Support\Facades\DB;
use Modules\Core\Tests\Stubs\Database\Db2PretendConnection;

it('registers the Db2 provider only when pdo_odbc is loaded', function (): void {
    $registered = app()->getProviders(DB2ServiceProvider::class) !== [];

    expect($registered)->toBe(extension_loaded('pdo_odbc'));
});

it('builds SQL through the pretend connection', function (): void {
    $queries = Db2PretendConnection::capture(static fn () => DB::table('products')->where('position', 1)->get());

    expect($queries)->toBe(['select * from products where "POSITION" = ?']);
});
