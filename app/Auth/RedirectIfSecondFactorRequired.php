<?php

declare(strict_types=1);

namespace Modules\Core\Auth;

use Illuminate\Contracts\Auth\StatefulGuard;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\LoginRateLimiter;
use Modules\Core\Auth\Services\AuthenticationService;
use Override;

/**
 * Fortify challenges every user with a confirmed TOTP, whatever the login method. A method that already
 * carries a second factor (external provider, passkey) skips the challenge; the others keep it.
 */
final class RedirectIfSecondFactorRequired extends RedirectIfTwoFactorAuthenticatable
{
    public function __construct(
        StatefulGuard $guard,
        LoginRateLimiter $limiter,
        private readonly AuthenticationService $authentication,
    ) {
        parent::__construct($guard, $limiter);
    }

    #[Override]
    public function handle($request, $next): mixed
    {
        if ($this->authentication->satisfiesSecondFactor($request)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
