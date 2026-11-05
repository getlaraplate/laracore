@use(\Modules\Core\Filament\Pages\Swagger)

<x-filament-panels::page>
    <div class="fi-page-content p-6">
        @php
            $grouped_modules = $this->getGroupedModules();
        @endphp

        <div class="prose dark:prose-invert max-w-none">
            <main class="mt-6">
                {{-- Masonry: each card spans as many 1px rows as its height plus the gap, so grid
                     auto-placement puts it in the shortest column while keeping the card order. --}}
                <div
                    x-data="{
                        layout() {
                            const gap = parseFloat(getComputedStyle($el).columnGap) || 0;
                            $el.style.gridAutoRows = '1px';

                            for (const card of $el.children) {
                                card.style.gridRowEnd = `span ${Math.ceil(card.getBoundingClientRect().height + gap)}`;
                            }
                        },
                    }"
                    x-init="layout(); const observer = new ResizeObserver(() => layout()); [...$el.children].forEach((card) => observer.observe(card))"
                    class="grid grid-cols-1 items-start gap-x-6 lg:grid-cols-2 lg:gap-x-8"
                >
                    <div id="docs-card" class="flex flex-col items-start gap-4 overflow-hidden rounded-lg bg-white p-6 shadow-[0px_14px_34px_0px_rgba(0,0,0,0.08)] ring-1 ring-white/[0.05] transition duration-300 hover:text-black/70 hover:ring-black/20 focus:outline-none focus-visible:ring-[#a5ac56] dark:bg-zinc-900 dark:ring-zinc-800 dark:hover:text-white/70 dark:hover:ring-zinc-700 dark:focus-visible:ring-[#a5ac56]">
                        @php
                            $app_info = $grouped_modules['App'] ?? [];
                        @endphp
                        <div class="flex flex-col gap-1 w-full">
                            <h2 class="text-xl font-semibold text-black dark:text-white">{{ config('app.name') }}</h2>
                            @if (! empty($app_info['description']))
                                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $app_info['description'] }}</p>
                            @endif
                        </div>

                        <div class="relative flex flex-col items-center gap-6 lg:items-end w-full">
                            <div id="docs-card-content" class="flex items-start gap-2 flex-col w-full">
                                <!-- migrations -->
                                <div class="text-sm/relaxed w-full">
                                    @php
                                    $done = migrations(true, true);
                                    $total = migrations(true, false);
                                    @endphp
                                    <i>{{ __('app.welcome.migrations') }}</i>
                                    <div class="ml-4 flex items-center gap-2" style="float: right">
                                        @if ($done !== $total)
                                            <x-core::alert-icon style="color: var(--warning-400);" />
                                        @else
                                            <x-core::check-icon style="color: var(--primary-400);" />
                                        @endif
                                        <span>{{ migrations(true, true) }} / {{ migrations(true, false) }}</span>
                                    </div>
                                </div>

                                <!-- api versions -->
                                <div class="text-sm/relaxed w-full">
                                    <i>{{ __('app.welcome.apiVersions') }}</i>
                                    <span style="float: right">{{ implode(', ', api_versions()) ?: __('app.welcome.apiDisabled') }}</span>
                                </div>

                                <!-- debug mode -->
                                <div class="text-sm/relaxed w-full">
                                    @php
                                    $debug = config('app.debug');
                                    @endphp
                                    <i>{{ __('app.welcome.debugMode') }}</i>
                                    <div class="flex items-center gap-2" style="float: right; {{ $debug ? 'color: var(--warning-400);' : 'color: var(--color-gray-600);' }}">
                                        @if ($debug)
                                            <x-core::alert-icon />
                                        @else
                                            <x-core::cancel-icon />
                                        @endif
                                    </div>
                                </div>

                                <!-- environment -->
                                <div class="text-sm/relaxed w-full">
                                    <i>{{ __('app.welcome.environment') }}</i>
                                    <span class="flex items-center" style="float: right">{{ config('app.env') }}</span>
                                </div>

                                <!-- maintenance -->
                                <div class="text-sm/relaxed w-full">
                                    <i>{{ __('app.welcome.maintenanceMode') }}</i>
                                    <div class="flex items-center" style="float: right">
                                        @if (app()->isDownForMaintenance())
                                            <x-core::alert-icon style="color: var(--warning-400);" />
                                        @else
                                            <x-core::cancel-icon style="color: var(--color-gray-600);" />
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- modules -->
                    @foreach ($grouped_modules as $module => $data)
                        <x-core::module :module="$module" :data="$data" />
                    @endforeach
                </div>
            </main>
        </div>
    </div>
</x-filament-panels::page>

