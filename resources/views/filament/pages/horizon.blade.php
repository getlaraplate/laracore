<style>
    .fi-main .fi-page {
        height: 100%;
    }
    .fi-main .fi-page .fi-page-header-main-ctn {
        height: 100%;
    }
    .fi-main .fi-page .fi-page-header-main-ctn .fi-page-main {
        height: 100%;
    }
    .fi-main .fi-page .fi-page-header-main-ctn .fi-page-main .fi-page-content {
        height: 100%;
    }
</style>

<x-filament-panels::page>
    @if(config('horizon.path'))
        {{--
            Horizon is embedded as is, its views are never published. Once the frame has loaded,
            the panel's palette and font are copied into it, the stylesheet below restyles Horizon's
            Bootstrap with them, and the frame follows the panel's dark mode. A Horizon update that
            renames its classes only brings back some of its own colours.
        --}}
        <div
            style="width: 100%; height: 100%;"
            x-data="{
                observer: null,
                palettes: ['primary', 'gray', 'danger', 'success', 'warning', 'info'],
                shades: [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950],
                isDark() {
                    return document.documentElement.classList.contains('dark');
                },
                init() {
                    try {
                        localStorage.setItem('horizonColorScheme', this.isDark() ? 'dark' : 'light');
                    } catch (error) {}

                    this.observer = new MutationObserver(() => this.sync());
                    this.observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
                },
                destroy() {
                    this.observer?.disconnect();
                },
                sync() {
                    const frame = this.$refs.frame?.contentDocument;

                    if (! frame?.head) {
                        return;
                    }

                    const dark = this.isDark();

                    try {
                        localStorage.setItem('horizonColorScheme', dark ? 'dark' : 'light');
                    } catch (error) {}

                    const darkSheet = frame.querySelector('style[data-scheme=dark]');

                    if (darkSheet) {
                        darkSheet.media = dark ? '' : 'max-width: 1px';
                    }

                    frame.documentElement.classList.toggle('dark', dark);

                    const panel = getComputedStyle(document.documentElement);
                    const variables = ['--font-family'];

                    this.palettes.forEach((palette) => this.shades.forEach((shade) => variables.push(`--${palette}-${shade}`)));

                    const declarations = variables
                        .map((name) => [name, panel.getPropertyValue(name).trim()])
                        .filter(([, value]) => value !== '')
                        .map(([name, value]) => `${name}: ${value};`)
                        .join(' ');

                    let style = frame.getElementById('laraplate-horizon-theme');

                    if (! style) {
                        style = frame.createElement('style');
                        style.id = 'laraplate-horizon-theme';
                        frame.head.appendChild(style);

                        document.querySelectorAll('link[rel=stylesheet][href*=fonts]').forEach((font) => {
                            frame.head.appendChild(font.cloneNode());
                        });
                    }

                    style.textContent = `:root { ${declarations} } ${this.$refs.theme.innerHTML}`;
                },
            }"
        >
            <template x-ref="theme">
                body { font-family: var(--font-family), ui-sans-serif, system-ui, sans-serif; background-color: var(--gray-50); color: var(--gray-950); }
                html.dark body { background-color: var(--gray-950); color: #fff; }
                button[title="Switch Theme"] { display: none; }

                a, .nav-link, .btn-link, .link-primary, .page-link { color: var(--primary-600); }
                html.dark a, html.dark .nav-link, html.dark .btn-link, html.dark .link-primary, html.dark .page-link { color: var(--primary-400); }
                .fill-primary, .sidebar .nav-item a.active svg { fill: var(--primary-600); }
                html.dark .fill-primary, html.dark .sidebar .nav-item a.active svg { fill: var(--primary-400); }
                button:hover .fill-primary { fill: #fff; }
                .control-action svg:hover { fill: var(--primary-600); }
                html.dark .control-action svg:hover { fill: var(--primary-400); }

                .btn-primary, .btn-primary:disabled, .btn-primary.disabled, .btn-muted.active, .progress-bar,
                .list-group-item.active, .page-item.active .page-link, .dropdown-item.active, .dropdown-item:active,
                .form-check-input:checked, .btn-check:checked + .btn-outline-primary, .btn-outline-primary.active, .btn-outline-primary:hover {
                    color: #fff; background-color: var(--primary-600); border-color: var(--primary-600);
                }
                .btn-primary:hover, .btn-primary:focus { color: #fff; background-color: var(--primary-500); border-color: var(--primary-500); }
                html.dark .btn-primary, html.dark .btn-muted.active, html.dark .progress-bar, html.dark .page-item.active .page-link,
                html.dark .btn-check:checked + .btn-outline-primary, html.dark .btn-outline-primary.active, html.dark .btn-outline-primary:hover {
                    background-color: var(--primary-500); border-color: var(--primary-500);
                }
                html.dark .btn-primary:hover, html.dark .btn-primary:focus { background-color: var(--primary-400); border-color: var(--primary-400); }
                .btn-outline-primary { color: var(--primary-600); border-color: var(--primary-600); }
                html.dark .btn-outline-primary { color: var(--primary-400); border-color: var(--primary-400); }
                .btn:focus, .btn-check:focus + .btn, .form-control:focus, .form-select:focus, .form-check-input:focus {
                    border-color: var(--primary-500); box-shadow: 0 0 0 .2rem color-mix(in oklab, var(--primary-500) 25%, transparent);
                }

                .header { border-bottom-color: var(--gray-200); }
                html.dark .header { border-bottom-color: color-mix(in oklab, #fff 10%, transparent); }
                .header .logo { color: var(--gray-950); }
                html.dark .header .logo { color: #fff; }
                .sidebar .nav-item a { color: var(--gray-700); border-radius: .5rem; }
                html.dark .sidebar .nav-item a { color: var(--gray-200); }
                .sidebar .nav-item a svg { fill: var(--gray-400); }
                html.dark .sidebar .nav-item a svg { fill: var(--gray-500); }
                .sidebar .nav-item a:hover, .sidebar .nav-item a.active { background-color: var(--gray-100); color: var(--primary-600); }
                html.dark .sidebar .nav-item a:hover, html.dark .sidebar .nav-item a.active { background-color: color-mix(in oklab, #fff 5%, transparent); color: var(--primary-400); }

                .card, .card .card-header, .card .nav-pills, .modal-content, .dropdown-menu { background-color: #fff; }
                html.dark .card, html.dark .card .card-header, html.dark .card .nav-pills, html.dark .modal-content, html.dark .dropdown-menu { background-color: var(--gray-900); }
                .card { border-radius: .75rem; box-shadow: 0 1px 2px 0 rgb(0 0 0 / .05), 0 0 0 1px color-mix(in oklab, var(--gray-950) 5%, transparent); }
                html.dark .card { box-shadow: 0 0 0 1px color-mix(in oklab, #fff 10%, transparent); }
                .card .card-header { border-top-left-radius: .75rem; border-top-right-radius: .75rem; }
                .card .table th, .card-bg-secondary, .btn-muted { background-color: var(--gray-50); }
                html.dark .card .table th, html.dark .card-bg-secondary, html.dark .btn-muted { background-color: color-mix(in oklab, #fff 5%, transparent); }
                .card .table:not(.table-borderless) td { border-top-color: var(--gray-200); }
                html.dark .card .table:not(.table-borderless) td { border-top-color: color-mix(in oklab, #fff 10%, transparent); }
                .card .nav-pills .nav-link.active { color: var(--primary-600); border-bottom-color: var(--primary-600); }
                html.dark .card .nav-pills .nav-link.active { color: var(--primary-400); border-bottom-color: var(--primary-400); }
                html.dark .form-control { background-color: color-mix(in oklab, #fff 5%, transparent); border-color: color-mix(in oklab, #fff 10%, transparent); }
                .list-enter:not(.dontanimate), .list-leave-to:not(.dontanimate) { background: var(--primary-50); }
                html.dark .list-enter:not(.dontanimate), html.dark .list-leave-to:not(.dontanimate) { background: color-mix(in oklab, var(--primary-500) 15%, transparent); }
            </template>

            <iframe
                x-ref="frame"
                x-on:load="sync()"
                src="{{ url(config('horizon.path')) }}"
                style="width: 100%; height: 100%; border: 0;"
                title="Laravel Horizon"
            ></iframe>
        </div>
    @else
        <div class="p-4 text-center text-gray-500">
            Horizon is not configured
        </div>
    @endif
</x-filament-panels::page>
