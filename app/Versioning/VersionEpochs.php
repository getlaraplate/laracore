<?php

declare(strict_types=1);

namespace Modules\Core\Versioning;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\VersionEpoch;

/**
 * The current schema epoch of each root class, read at its first write in the process and kept for the rest of it
 * (D33): a migration flushes it, and a deploy restarts the workers that hold it.
 */
final class VersionEpochs
{
    /**
     * @var array<string, VersionEpoch>
     */
    private array $current = [];

    /**
     * @var array<string, bool>
     */
    private array $several = [];

    public function __construct(private readonly EpochFingerprint $fingerprint) {}

    public function currentFor(Model $root): VersionEpoch
    {
        $key = $root::class . '|' . $root->getConnectionName() . '|' . $root->getTable();

        if (isset($this->current[$key])) {
            return $this->current[$key];
        }

        $definition = $this->fingerprint->definitionOf($root);
        $fingerprint = $this->fingerprint->fingerprintOf($definition);
        $root_type = $root->getMorphClass();
        $query = new VersionEpoch()->setConnection($root->getConnectionName())->newQuery();
        // One read answers both questions, so that hasSeveral() costs no query at the writes that follow.
        $known = $query->clone()->where('root_type', $root_type)->get();
        $current = $known->firstWhere('fingerprint', $fingerprint)
            // createOrFirst absorbs the unique violation of two processes seeing a new shape at once.
            ?? $query->createOrFirst(
                ['root_type' => $root_type, 'fingerprint' => $fingerprint],
                ['definition' => $definition, 'first_seen_at' => now()],
            );
        $this->several[$root_type] = $known->count() + ($current->wasRecentlyCreated ? 1 : 0) > 1;

        return $this->current[$key] = $current;
    }

    /**
     * Whether the root class has known more than one shape: only then can a row of a root be of another epoch.
     */
    public function hasSeveral(string $rootType): bool
    {
        return $this->several[$rootType] ??= VersionEpoch::query()->where('root_type', $rootType)->limit(2)->count() > 1;
    }

    public function flush(): void
    {
        $this->current = [];
        $this->several = [];
    }
}
