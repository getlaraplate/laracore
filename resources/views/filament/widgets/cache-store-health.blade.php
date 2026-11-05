<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <x-filament::icon icon="heroicon-o-archive-box" class="w-5 h-5 inline mr-2" />
            {{ __('app.widgets.cache') }}
            <span class="fi-section-header-description text-sm font-normal text-gray-500 dark:text-gray-400">
                @if($failover)
                    {{ ucfirst($default) }} ({{ __('app.widgets.failoverChain') }})
                @else
                    {{ ucfirst($stores[0]['driver']) }}@if(! empty($stores[0]['version'])) v{{ $stores[0]['version'] }}@endif
                @endif
            </span>
        </x-slot>

        {{-- A single Redis store is fully described by the header and the connections table. --}}
        @if($failover || $redis_connections === [])
        <div class="fi-section-content">
            <div class="fi-ta">
                <div class="fi-ta-ctn fi-ta-ctn-with-header">
                    <div class="fi-ta-main">
                        <div class="fi-ta-content-ctn">
                            <table class="fi-ta-table">
                                <thead>
                                    <tr>
                                        <th class="fi-ta-header-cell">{{ __('app.widgets.store') }}</th>
                                        <th class="fi-ta-header-cell">{{ __('app.widgets.driver') }}</th>
                                        <th class="fi-ta-header-cell">{{ __('app.widgets.connection') }}</th>
                                        <th class="fi-ta-header-cell">{{ __('app.widgets.version') }}</th>
                                        <th class="fi-ta-header-cell">{{ __('app.fields.status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($stores as $store)
                                        <tr class="fi-ta-row" wire:key="cache-store-{{ $store['name'] }}">
                                            <td class="fi-ta-cell">
                                                <div class="fi-ta-col">
                                                    <div class="fi-ta-text-item fi-ta-text">{{ $store['name'] }}</div>
                                                </div>
                                            </td>
                                            <td class="fi-ta-cell">
                                                <div class="fi-ta-col">
                                                    <div class="fi-ta-text-item fi-ta-text">{{ ucfirst($store['driver']) }}</div>
                                                </div>
                                            </td>
                                            <td class="fi-ta-cell">
                                                <div class="fi-ta-col">
                                                    <div class="fi-ta-text-item fi-ta-text">{{ $store['connection'] ?? '-' }}</div>
                                                </div>
                                            </td>
                                            <td class="fi-ta-cell">
                                                <div class="fi-ta-col">
                                                    <div class="fi-ta-text-item fi-ta-text">{{ $store['version'] ?? '-' }}</div>
                                                </div>
                                            </td>
                                            <td class="fi-ta-cell">
                                                <div class="fi-ta-col">
                                                    <div class="fi-ta-text-item fi-ta-text fi-color {{ $store['reachable'] ? 'fi-color-success fi-text-color-700 dark:fi-text-color-400' : 'fi-color-danger fi-text-color-500 dark:fi-text-color-600' }}">{{ $store['reachable'] ? __('app.widgets.reachable') : __('app.widgets.unreachable') }}</div>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endif

        @if($redis_connections !== [])
            <div @class(['fi-section-content', 'mt-6' => $failover])>
                <div class="fi-ta">
                    <div class="fi-ta-ctn fi-ta-ctn-with-header">
                        <div class="fi-ta-main">
                            <div class="fi-ta-content-ctn">
                                <table class="fi-ta-table">
                                    <thead>
                                        <tr>
                                            <th class="fi-ta-header-cell">{{ __('app.widgets.connection') }}</th>
                                            <th class="fi-ta-header-cell">{{ __('app.fields.status') }}</th>
                                            <th class="fi-ta-header-cell text-right">{{ __('app.widgets.keys') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($redis_connections as $redis_connection)
                                            <tr class="fi-ta-row" wire:key="redis-connection-{{ implode('-', $redis_connection['names']) }}">
                                                <td class="fi-ta-cell">
                                                    <div class="fi-ta-col">
                                                        <div class="fi-ta-text-item fi-ta-text">{{ implode(', ', $redis_connection['names']) }}</div>
                                                    </div>
                                                </td>
                                                <td class="fi-ta-cell">
                                                    <div class="fi-ta-col">
                                                        <div class="fi-ta-text-item fi-ta-text fi-color {{ $redis_connection['reachable'] ? 'fi-color-success fi-text-color-700 dark:fi-text-color-400' : 'fi-color-danger fi-text-color-500 dark:fi-text-color-600' }}">{{ $redis_connection['reachable'] ? __('app.widgets.reachable') : __('app.widgets.unreachable') }}</div>
                                                    </div>
                                                </td>
                                                <td class="fi-ta-cell">
                                                    <div class="fi-ta-col">
                                                        <div class="fi-ta-text-item fi-ta-text text-right">{{ $redis_connection['keys'] === null ? '-' : number_format($redis_connection['keys']) }}</div>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
