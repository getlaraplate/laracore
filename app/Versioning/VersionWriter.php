<?php

declare(strict_types=1);

namespace Modules\Core\Versioning;

use Illuminate\Support\Facades\Date;
use LogicException;
use Modules\Core\Enums\VersionChangeType;
use Modules\Core\Models\DynamicEntity;
use Modules\Core\Models\Version;
use Modules\Core\Versioning\Contracts\VersionSetManagerInterface;
use Modules\Core\Versioning\Contracts\VersionWriterInterface;
use Modules\Core\Versioning\Data\VersionChange;
use Modules\Core\Versioning\Data\VersionSetOptions;
use Modules\Core\Versioning\Data\VersionSetRoot;
use Override;

final readonly class VersionWriter implements VersionWriterInterface
{
    public function __construct(private VersionSetManagerInterface $manager) {}

    #[Override]
    public function write(VersionChange $change): Version
    {
        if ($this->manager->current() === null) {
            $written = $this->manager->runAfterTheFact(
                VersionSetRoot::forModel($change->model),
                fn (): Version => $this->write($change),
                new VersionSetOptions(actor: $change->userId),
            );

            if (! $written instanceof Version) {
                throw new LogicException('The version set operation did not return a version.');
            }

            return $written;
        }

        $connection_name = $change->model->getConnection()->getName();

        if (! is_string($connection_name) || $connection_name === '') {
            throw new LogicException('A version change requires a named database connection.');
        }

        $this->manager->enlist($connection_name);
        // The set of the change's own root, never the set of the operation that caused it (D43).
        $active = $this->manager->setFor($change->model);

        if ($change->type === VersionChangeType::Created && $change->relationPath === null && $change->model === $active->root()->model()) {
            $active->storeAsBaseline();
        }

        $version_set = $active->versionSet();
        $version_class = method_exists($change->model, 'getVersionModel')
            ? $change->model->getVersionModel()
            : config('versionable.version_model', Version::class);
        $version = new $version_class;

        if (! $version instanceof Version) {
            throw new LogicException('The configured version model must extend ' . Version::class . '.');
        }

        $version->setConnection($connection_name);
        $version->forceFill([
            'version_set_id' => $version_set->getKey(),
            'sequence' => $active->nextSequence(),
            'change_type' => $change->type,
            'relation_path' => $change->relationPath,
            'subject_key' => $change->subjectKey,
            'versionable_id' => $change->model->getKey(),
            'versionable_type' => $change->model->getMorphClass(),
            $this->userForeignKey($change->model) => $change->userId,
            'original_contents' => $change->originalContents,
            'contents' => $change->contents,
            'version_strategy' => $change->strategy,
        ]);

        if ($change->time !== null) {
            $version->setAttribute('created_at', Date::parse($change->time));
        }

        if (class_exists(DynamicEntity::class) && $change->model instanceof DynamicEntity) {
            $version->connection_ref = $change->model->getConnectionName();
            $version->table_ref = $change->model->getTable();
        }

        $version->saveOrFail();
        $active->markVersionWritten();
        $active->noteStream($change->relationPath);

        return $version;
    }

    private function userForeignKey(object $model): string
    {
        return method_exists($model, 'getUserForeignKeyName')
            ? $model->getUserForeignKeyName()
            : config('versionable.user_foreign_key', 'user_id');
    }
}
