<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;

/**
 * Package discovery orders eloquent-spatial's DatabaseServiceProvider subclass after the Db2 provider, which
 * rebinds `db`; the drivers must survive that on the real application.
 */
it('keeps the Db2 drivers registered on the booted database manager', function (): void {
    /** @var DatabaseManager $manager */
    $manager = app('db');
    $extensions = (fn (): array => $this->extensions)->call($manager);

    expect($extensions)->toHaveKeys(['db2', 'vms_rms_gateway']);
});
