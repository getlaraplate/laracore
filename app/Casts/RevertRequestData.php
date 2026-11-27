<?php

declare(strict_types=1);

namespace Modules\Core\Casts;

use Modules\Core\Enums\RevertMode;
use Modules\Core\Http\Requests\RevertRequest;

final class RevertRequestData extends DetailRequestData
{
    public readonly int $revision;

    public readonly int $expectedRevision;

    public readonly RevertMode $mode;

    public readonly bool $force;

    /**
     * @param  string|array<string>  $primaryKey
     */
    public function __construct(RevertRequest $request, string $mainEntity, array $validated, string|array $primaryKey, ?string $module = null)
    {
        parent::__construct($request, $mainEntity, $validated, $primaryKey, $module);
        $this->revision = is_numeric($validated['revision'] ?? null) ? (int) $validated['revision'] : 0;
        $this->expectedRevision = is_numeric($validated['expected_revision'] ?? null) ? (int) $validated['expected_revision'] : 0;
        $this->mode = RevertMode::tryFrom(is_string($validated['mode'] ?? null) ? $validated['mode'] : '') ?? RevertMode::Complete;
        $this->force = filter_var($validated['force'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
