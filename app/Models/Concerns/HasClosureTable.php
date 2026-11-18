<?php

declare(strict_types=1);

namespace Modules\Core\Models\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use LogicException;
use Modules\Core\Helpers\TreeCollection;

/**
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 *
 * @template TModel of Model
 */
trait HasClosureTable
{
    public static function rebuildClosure(): void
    {
        $model = new static;
        $model->getConnection()->table($model->getClosureTable())->truncate();

        static::query()
            ->whereNull('parent_id')
            ->orderBy('id')
            ->chunkById(100, static function (Collection $rootNodes): void {
                foreach ($rootNodes as $root) {
                    self::insertClosures($root);
                }
            });
    }

    /**
     * @return BelongsToMany<static>
     */
    public function ancestors(): BelongsToMany
    {
        $closureTable = $this->getClosureTable();
        $modelTable = $this->getModelTable();

        return $this->belongsToMany(
            static::class,
            $closureTable,
            'descendant_id',
            'ancestor_id',
        )
            ->withPivot('depth')
            ->orderBy($this->qualifyTreeColumn('depth', $closureTable), 'desc')
            ->select([
                $this->qualifyTreeColumn('*', $modelTable),
                $this->qualifyTreeColumn('depth', $closureTable),
            ]);
    }

    /**
     * @return BelongsToMany<static>
     */
    public function descendants(): BelongsToMany
    {
        $closureTable = $this->getClosureTable();
        $modelTable = $this->getModelTable();

        return $this->belongsToMany(
            static::class,
            $closureTable,
            'ancestor_id',
            'descendant_id',
        )
            ->withPivot('depth')
            ->orderBy($this->qualifyTreeColumn('depth', $closureTable), 'asc')
            ->select([
                $this->qualifyTreeColumn('*', $modelTable),
                $this->qualifyTreeColumn('depth', $closureTable),
            ]);
    }

    /**
     * @return BelongsToMany<static>
     */
    public function closure(): BelongsToMany
    {
        $closureTable = $this->getClosureTable();
        $modelTable = $this->getModelTable();

        return $this->belongsToMany(
            static::class,
            $closureTable,
            'descendant_id',
            'ancestor_id',
        )
            ->withPivot('depth')
            ->from($modelTable . ' as closure_categories')
            ->select('closure_categories.*');
    }

    /**
     * @return HasMany<static>
     */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id');
    }

    /**
     * @return BelongsTo<static>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    /**
     * @return HasMany<static>
     */
    public function siblings(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id')
            ->where('id', '!=', $this->id);
    }

    /**
     * @return HasMany<static>
     */
    public function siblingsAndSelf(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id');
    }

    /**
     * @return Collection<int, static>
     */
    public function bloodline(): Collection
    {
        return $this->ancestors->reverse()->push($this)->merge($this->descendants);
    }

    /**
     * @return Collection<int, static>
     */
    public function bloodlineAndSelf(): Collection
    {
        return $this->ancestors->reverse()->push($this);
    }

    public function getDepth(): int
    {
        $cache_key = $this->depthCacheKey();
        $closureTable = $this->getClosureTable();

        // The in-request memo sits on the memoized store rather than in a static array:
        // a static L1 grows by one entry per node the process ever touches, which never
        // shrinks on a long-lived worker. The memoized store is bound per request and
        // still exposes forget(), which invalidateDepthCache() needs.
        return (int) Cache::memo()->remember(
            $cache_key,
            now()->addHours(24),
            fn () => $this->getConnection()->table($closureTable)
                ->where($this->qualifyTreeColumn('ancestor_id', $closureTable), $this->id)
                ->where($this->qualifyTreeColumn('descendant_id', $closureTable), $this->id)
                ->value($this->qualifyTreeColumn('depth', $closureTable)) ?? 0,
        );
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    public function isLeaf(): bool
    {
        return $this->children()->count() === 0;
    }

    public function isDescendantOf(self $ancestor): bool
    {
        $closureTable = $this->getClosureTable();

        return $this->getConnection()->table($closureTable)
            ->where($this->qualifyTreeColumn('ancestor_id', $closureTable), $ancestor->id)
            ->where($this->qualifyTreeColumn('descendant_id', $closureTable), $this->id)
            ->where($this->qualifyTreeColumn('depth', $closureTable), '>', 0)
            ->exists();
    }

    public function isAncestorOf(self $descendant): bool
    {
        $closureTable = $this->getClosureTable();

        return $this->getConnection()->table($closureTable)
            ->where($this->qualifyTreeColumn('ancestor_id', $closureTable), $this->id)
            ->where($this->qualifyTreeColumn('descendant_id', $closureTable), $descendant->id)
            ->where($this->qualifyTreeColumn('depth', $closureTable), '>', 0)
            ->exists();
    }

    public function isSiblingOf(self $sibling): bool
    {
        return $this->parent_id === $sibling->parent_id && $this->id !== $sibling->id;
    }

    public function moveTo(self $newParent): bool
    {
        throw_if(
            $this->getConnection()->getName() !== $newParent->getConnection()->getName(),
            LogicException::class,
            'Cannot move tree nodes across multiple database connections.',
        );

        if ($newParent->isDescendantOf($this)) {
            return false;
        }

        $this->getConnection()->transaction(function () use ($newParent): void {
            $this->parent_id = $newParent->id;
            $this->save();
            $this->updateClosureTable();
        });

        return true;
    }

    public function newCollection(array $models = []): TreeCollection
    {
        return new TreeCollection($models);
    }

    public function getClosureTable(): string
    {
        return $this->getTable() . '_closure';
    }

    public function getParentKeyName(): string
    {
        return 'parent_id';
    }

    protected static function insertClosures($model, $ancestors = []): void
    {
        $selfRow = [
            'ancestor_id' => $model->id,
            'descendant_id' => $model->id,
            'depth' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $rows = [$selfRow];

        foreach ($ancestors as $i => $ancestor) {
            $rows[] = [
                'ancestor_id' => $ancestor->id,
                'descendant_id' => $model->id,
                'depth' => $i + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Batch insert for better performance
        if ($rows !== []) {
            $model->getConnection()->table($model->getClosureTable())->insert($rows);
        }

        $children = $model->children()->orderBy('id')->get();

        foreach ($children as $child) {
            self::insertClosures($child, array_merge([$model], $ancestors));
        }
    }

    protected static function bootHasClosureTable(): void
    {
        static::created(function ($model): void {
            $model->updateClosureTable();
        });

        static::updated(function ($model): void {
            if ($model->isDirty('parent_id')) {
                $model->updateClosureTable();
            }
        });

        static::deleted(function ($model): void {
            $model->getConnection()->table($model->getClosureTable())
                ->where('descendant_id', $model->id)
                ->orWhere('ancestor_id', $model->id)
                ->delete();
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function withClosure(Builder $query): Builder
    {
        return $query->with(['closure', 'ancestors']);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function tree(Builder $query): Builder
    {
        $closureTable = $this->getClosureTable();
        // $modelTable = $this->getModelTable();

        return $query->withClosure()
            ->whereDoesntHave('ancestors')
            ->with([
                'closure' => fn ($query) => $query->orderBy($this->qualifyTreeColumn('depth', $closureTable)),
            ]);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function withSiblings(Builder $query): Builder
    {
        return $query->with(['siblings', 'siblingsAndSelf']);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function withBloodline(Builder $query): Builder
    {
        return $query->with(['ancestors', 'descendants']);
    }

    protected function getModelTable(): string
    {
        return $this->getTable();
    }

    protected function qualifyTreeColumn(string $column, ?string $table = null): string
    {
        $table ??= $this->getModelTable();

        return sprintf('%s.%s', $table, $column);
    }

    protected function updateClosureTable(): void
    {
        $closureTable = $this->getClosureTable();

        // Delete old closure entries for this node
        $this->getConnection()->table($closureTable)
            ->where($this->qualifyTreeColumn('descendant_id', $closureTable), $this->id)
            ->delete();

        // Prepare rows for batch insert
        $rows = [
            [
                'ancestor_id' => $this->id,
                'descendant_id' => $this->id,
                'depth' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        // Insert records for all ancestors (batch insert for better performance)
        if ($this->parent_id) {
            $ancestors = $this->getConnection()->table($closureTable)
                ->where($this->qualifyTreeColumn('descendant_id', $closureTable), $this->parent_id)
                ->get();

            foreach ($ancestors as $ancestor) {
                $rows[] = [
                    'ancestor_id' => $ancestor->ancestor_id,
                    'descendant_id' => $this->id,
                    'depth' => $ancestor->depth + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Batch insert all rows at once (more efficient than multiple inserts)
        if ($rows !== []) {
            $this->getConnection()->table($closureTable)->insert($rows);
        }

        // Note: When a node moves, its descendants' closure entries also need to be updated
        // This is currently handled by calling rebuildClosure() when needed, or can be
        // optimized in the future with a more efficient SQL-based approach

        // Clears both the in-request memo and the persistent entry for this node only.
        Cache::memo()->forget($this->depthCacheKey());
    }

    private function depthCacheKey(): string
    {
        return sprintf('%s.%s.%s.depth', $this->getConnection()->getName(), $this->getTable(), $this->id);
    }
}
