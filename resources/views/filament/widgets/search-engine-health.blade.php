<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <x-filament::icon icon="heroicon-o-magnifying-glass" class="w-5 h-5 inline mr-2" />
            {{ __('app.widgets.searchEngine') }}
            <span class="fi-section-header-description text-sm font-normal text-gray-500 dark:text-gray-400">
                {{ ucfirst($driver ?? 'Unknown') }}@if(! empty($version)) v{{ $version }}@endif
                @isset($cache_minutes)
                    (cached ~{{ $cache_minutes }} min)
                @endisset
            </span>
        </x-slot>

        @isset($error)
            <div class="fi-alert fi-color-danger fi-size-sm">
                <div class="fi-alert-icon">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" />
                </div>
                <div class="fi-alert-content">
                    <strong>{{ __('app.widgets.error') }}</strong> {{ $error }}
                </div>
            </div>
        @else
            <div class="fi-section-content">
                <div class="fi-ta">
                    <div class="fi-ta-ctn fi-ta-ctn-with-header">
                        <div class="fi-ta-main">
                            <div class="fi-ta-content-ctn">
                                <table class="fi-ta-table">
                                    <thead>
                                        <tr>
                                            <th class="fi-ta-header-cell">{{ __('app.widgets.model') }}</th>
                                            <th class="fi-ta-header-cell">{{ __('app.widgets.index') }}</th>
                                            <th class="fi-ta-header-cell">{{ __('app.fields.status') }}</th>
                                            <th class="fi-ta-header-cell text-right">{{ __('app.widgets.records') }}</th>
                                            <th class="fi-ta-header-cell text-right">{{ __('app.widgets.documents') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($models as $model)
                                            <tr class="fi-ta-row">
                                                <td class="fi-ta-cell">
                                                    <div class="fi-ta-col">
                                                        <div class="fi-ta-text-item fi-ta-text">{{ $model['name'] }}</div>
                                                    </div>
                                                </td>
                                                <td class="fi-ta-cell">
                                                    <div class="fi-ta-col">
                                                        <div class="fi-ta-text-item fi-ta-text">{{ $model['searchable_as'] }}</div>
                                                    </div>
                                                </td>
                                                <td class="fi-ta-cell">
                                                    <div class="fi-ta-col">
                                                        <div class="fi-ta-text-item fi-ta-text fi-color {{$model['index_exists'] ? 'fi-color-success fi-text-color-700 dark:fi-text-color-400' : 'fi-color-danger fi-text-color-500 dark:fi-text-color-600' }}">{{ $model['index_exists'] ? 'Active' : 'Missing' }}</div>
                                                    </div>
                                                </td>
                                                <td class="fi-ta-cell">
                                                    <div class="fi-ta-col">
                                                        <div class="fi-ta-text-item fi-ta-text text-right">{{ number_format($model['count']) }}</div>
                                                    </div>
                                                </td>
                                                <td class="fi-ta-cell">
                                                    <div class="fi-ta-col">
                                                        <div class="fi-ta-text-item fi-ta-text text-right fi-color {{ $model['count'] === $model['documents'] ? 'fi-text-color-700 dark:fi-text-color-400' : 'fi-text-color-500 dark:fi-text-color-600' }}">{{ number_format($model['documents']) }}</div>
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
        @endisset
    </x-filament::section>
</x-filament-widgets::widget>
