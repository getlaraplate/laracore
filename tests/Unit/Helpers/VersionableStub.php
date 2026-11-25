<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit\Helpers;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Contracts\IVersionedModel;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\Concerns\HasVersions;

final class VersionableStub extends Model implements IVersionedModel
{
    use HasVersions;

    protected $table = 'versionables';

    protected VersionStrategy $versionStrategy = VersionStrategy::DIFF;

    public function shouldBeVersioning(): bool
    {
        return true;
    }
}
