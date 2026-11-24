<?php

declare(strict_types=1);

use Modules\Core\Search\Extensions\ExtensionRegistry;

/*
 * Every module registers its extenders into one registry at boot, and every host reads them from it: the container
 * binds it as a singleton. Moved from the tests of the CMS `ContentExtenderRegistry`, removed once every extender
 * registered in Core.
 */

it('is one shared instance of the container', function (): void {
    app()->forgetInstance(ExtensionRegistry::class);

    expect(app(ExtensionRegistry::class))->toBe(app(ExtensionRegistry::class));
});
