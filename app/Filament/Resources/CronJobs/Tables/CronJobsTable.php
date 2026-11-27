<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\CronJobs\Tables;

use Filament\Actions\Action;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Modules\Core\Filament\Utils\HasTable;
use Modules\Core\Models\CronJob;

final class CronJobsTable
{
    use HasTable;

    public static function configure(Table $table): Table
    {
        return self::configureTable(
            table: $table,
            columns: static function (Collection $default_columns): void {
                $default_columns->unshift(...[
                    TextColumn::make('name')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('command')
                        ->searchable()
                        ->toggleable(),
                    TextColumn::make('schedule')
                        ->searchable()
                        ->grow(false)
                        // ->formatStateUsing(static function (string $state): string {
                        //     // Each part is padded with spaces for monospace alignment.
                        //     // In HTML, use &nbsp; for each inner space, but beware that browsers will collapse whitespace unless all spaces are &nbsp;.
                        //     // We generate a string with fixed 4-character blocks, but convert all spaces to &nbsp;.
                        //     $parts = explode(' ', $state);
                        //     $formatted = '';

                        //     foreach ($parts as $i => $part) {
                        //         // Pad left with regular spaces to length 4, then convert all spaces to &nbsp;
                        //         $padded = mb_str_pad($part, 4, ' ', STR_PAD_LEFT);
                        //         $html = str_replace(' ', '&nbsp;', $padded);
                        //         $formatted .= $html;

                        //         // Add a space between blocks (as &nbsp;), except after last
                        //         if ($i < count($parts) - 1) {
                        //             $formatted .= '&nbsp;';
                        //         }
                        //     }

                        //     return '<span style="font-family: monospace;">' . $formatted . '</span>';
                        // })
                        // ->html()
                        ->alignRight()
                        ->toggleable(),
                    self::toggleColumn('without_overlapping')
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('runs')
                        ->formatStateUsing(static fn (CronJob $record): string => sprintf(
                            'Last run: %s<br>Next run: %s',
                            $record->previousRunAt()?->format('Y-m-d H:i:s'),
                            $record->nextRunAt()?->format('Y-m-d H:i:s'),
                        ))
                        ->toggleable(isToggledHiddenByDefault: true)
                        ->grow(false)
                        ->html(),
                ]);

                // The activation icon becomes a switch.
                $default_columns->transform(static fn (Column $column): Column => $column->getName() === CronJob::activationColumn()
                    ? self::toggleColumn(CronJob::activationColumn())->toggleable(isToggledHiddenByDefault: false)
                    : $column);
            },
            actions: static function (Collection $default_actions): void {
                $default_actions->unshift(Action::make('run')
                    ->icon('heroicon-o-play')
                    ->action(static fn (CronJob $record) => $record->run())
                    ->requiresConfirmation());
            },
        );
    }
}
