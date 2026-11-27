<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Plugins;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Models\User;

/**
 * Saves the timezone of the browser on the signed in user, once.
 *
 * The browser is the only place that knows the timezone of the reader. A user who has none saved gets a
 * small script that sends it to the self-service preferences endpoint; once saved, the script is no longer
 * rendered, and a value the user picked in the profile is never replaced.
 */
final class BrowserTimezonePlugin implements Plugin
{
    public static function make(): self
    {
        return app(self::class);
    }

    public function getId(): string
    {
        return 'core-browser-timezone';
    }

    public function register(Panel $panel): void
    {
        $panel->renderHook(
            PanelsRenderHook::BODY_END,
            fn (): string => $this->render(),
        );
    }

    public function boot(Panel $panel): void
    {
        //
    }

    /**
     * The script for the current user, or an empty string when there is no user or one is already saved.
     */
    public function render(): string
    {
        $user = Auth::user();

        if (! $user instanceof User || filled($user->timezone)) {
            return '';
        }

        return view('core::filament.browser-timezone.script', [
            'url' => route('core.auth.updatePreferences'),
            'token' => csrf_token(),
        ])->render();
    }
}
