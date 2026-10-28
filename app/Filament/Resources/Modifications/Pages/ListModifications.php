<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\Modifications\Pages;

use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Grouping\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Core\Filament\Resources\Modifications\ModificationResource;
use Modules\Core\Filament\Utils\HasRecords;
use Modules\Core\Models\Concerns\HasApprovals;
use Override;

final class ListModifications extends ListRecords
{
    use HasRecords;

    #[Override]
    protected static string $resource = ModificationResource::class;

    /**
     * Build tabs with badges from a single grouped query instead of N+1 count() queries,
     * cached for `core.filament.tabs_counts_ttl_seconds`.
     */
    public function getTabs(): array
    {
        /** @var class-string<Model> $model */
        $model = self::getResource()::getModel();

        $cache_key = 'filament_core_modifications_tabs_' . $model;

        /** @var array<string, int> $counts */
        $counts = Cache::remember($cache_key, $this->tabsCountsTtl(), static function () use ($model): array {
            $counts_by_type = [];

            foreach ($model::query()
                ->selectRaw('modifiable_type, count(*) as aggregate_count')
                ->groupBy('modifiable_type')
                ->pluck('aggregate_count', 'modifiable_type') as $type => $count) {
                $counts_by_type[(string) $type] = (int) $count;
            }

            return array_merge(['all' => array_sum($counts_by_type)], $counts_by_type);
        });

        if (count($counts) < 2) {
            return [];
        }

        $tabs = [
            'all' => Tab::make(__('app.sections.all'))->badge($counts['all']),
        ];

        $types = models(filter: fn (string $type): bool => class_uses_trait($type, HasApprovals::class));

        foreach ($types as $type) {
            $totals = (int) ($counts[$type] ?? 0);

            if ($totals === 0) {
                continue;
            }

            $label = Str::afterLast($type, '\\');

            $tabs[$type] = Tab::make($label)
                ->badge($totals)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('modifiable_type', $type));
        }

        $this->groups[] = Group::make('modifiable_type')
            ->label(__('app.fields.modifiable_type'))
            ->getTitleFromRecordUsing(function (Model $record): string {
                $type = $record->getAttribute('modifiable_type');

                return is_string($type) ? Str::studly($type) : '';
            });

        return $tabs;
    }

    private function tabsCountsTtl(): int
    {
        return max(1, (int) config('core.filament.tabs_counts_ttl_seconds', 60));
    }
}
