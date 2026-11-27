<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Core\Actions\Users\GetUserInfoAction;
use Modules\Core\Actions\Users\HandleSocialLoginAction;
use Modules\Core\Actions\Users\ImpersonateUserAction;
use Modules\Core\Actions\Users\LeaveImpersonationAction;
use Modules\Core\Helpers\ResponseBuilder;
use Modules\Core\Http\Requests\ImpersonationRequest;
use Modules\Core\Http\Requests\UpdatePreferencesRequest;
use Modules\Core\Http\Resources\UserInfoResponse;
use Modules\Core\Models\User;
use Modules\Core\Rules\PreferencesBag;

final class UserController extends Controller
{
    public function __construct(
        private readonly GetUserInfoAction $getUserInfoAction,
        private readonly ImpersonateUserAction $impersonateUserAction,
        private readonly LeaveImpersonationAction $leaveImpersonationAction,
        private readonly HandleSocialLoginAction $handleSocialLoginAction,
    ) {
        parent::__construct();
    }

    /**
     * @return array<array<mixed|array<string>>|false|int|mixed|string>
     *
     * @psalm-return array{id: 'anonymous'|int, name: string, username: string, email: string, groups: array<int, mixed>, canImpersonate: false|mixed, permissions: array<list<string>>}
     */
    public static function parseUserInfo(?User $user = null): UserInfoResponse
    {
        return new UserInfoResponse($user);
    }

    public static function parseAnonymousUserInfo(): array
    {
        return self::parseUserInfo();
    }

    public function userInfo(Request $request): \Illuminate\Http\JsonResponse
    {
        /** @var User|null $user */
        $user = Auth::user();

        // questo riassegna una licenza all'utente in sessione se da comando si è fatto un aggiornamento delle licenze che ha disassociato i riferimenti
        try {
            return new ResponseBuilder($request)
                ->setData(($this->getUserInfoAction)($user))
                ->json();
        } catch (AuthorizationException $unauthorizedException) {
            return new ResponseBuilder($request)
                ->setError($unauthorizedException->getMessage())
                ->setStatus(Response::HTTP_UNAUTHORIZED)
                ->json();
        }
    }

    public function impersonate(ImpersonationRequest $request): \Illuminate\Http\JsonResponse
    {
        $user_to_impersonate_id = $request->validated()['user'];
        $user_to_impersonate = user_class()::findOrFail($user_to_impersonate_id);

        /** @var User $current_user */
        $current_user = Auth::user();

        return new ResponseBuilder($request)
            ->setData(($this->impersonateUserAction)($current_user, $user_to_impersonate))
            ->json();
    }

    public function leaveImpersonate(Request $request): \Illuminate\Http\JsonResponse
    {
        /** @var User $current_user */
        $current_user = Auth::user();

        return new ResponseBuilder($request)
            ->setData(($this->leaveImpersonationAction)($current_user))
            ->json();
    }

    public function socialLoginRedirect(string $service): \Symfony\Component\HttpFoundation\RedirectResponse|RedirectResponse
    {
        return $this->handleSocialLoginAction->redirect($service);
    }

    public function socialLoginCallback(string $service): Redirector|RedirectResponse
    {
        return ($this->handleSocialLoginAction)($service);
    }

    public function maintainSession(): \Illuminate\Http\JsonResponse
    {
        return Auth::user()
            ? response()->json(['message' => 'Session maintained successfully.'])
            : response()->json(['error' => 'Unauthorized'], 401);
    }

    /**
     * Persist the caller's own UI preferences, language and timezone and echo back the refreshed profile.
     * Each preferences namespace received replaces the stored one and a null removes it; the others are
     * left as they are, so two clients or two devices cannot erase each other. `lang` and `timezone`
     * are written only when sent, and a null clears them.
     */
    public function updatePreferences(UpdatePreferencesRequest $request): \Illuminate\Http\JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $attributes = [];

        if ($request->has('preferences')) {
            $bag = $user->preferences ?? [];

            $preferences = $request->validated('preferences');

            foreach (is_array($preferences) ? $preferences : [] as $namespace => $value) {
                if ($value === null) {
                    unset($bag[$namespace]);
                } else {
                    $bag[$namespace] = $value;
                }
            }

            if (! PreferencesBag::fits($bag)) {
                throw ValidationException::withMessages([
                    'preferences' => 'The preferences may not exceed ' . PreferencesBag::MAX_BYTES . ' bytes once merged.',
                ]);
            }

            $attributes['preferences'] = $bag;
        }

        foreach (['lang', 'timezone'] as $column) {
            if ($request->has($column)) {
                $attributes[$column] = $request->validated($column);
            }
        }

        return $this->storeAttributes($request, $user, $attributes);
    }

    /**
     * Clear the caller's whole preferences bag.
     */
    public function deletePreferences(Request $request): \Illuminate\Http\JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        return $this->storeAttributes($request, $user, ['preferences' => []]);
    }

    /**
     * Clear one namespace of the caller's preferences bag.
     */
    public function deletePreferencesNamespace(Request $request, string $namespace): \Illuminate\Http\JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $bag = $user->preferences ?? [];
        unset($bag[$namespace]);

        return $this->storeAttributes($request, $user, ['preferences' => $bag]);
    }

    /**
     * Mark the caller's onboarding flow as done and echo back the refreshed profile.
     */
    public function completeFirstLogin(Request $request): \Illuminate\Http\JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $user->forceFill(['is_first_login' => false])->saveQuietly();

        return new ResponseBuilder($request)
            ->setData(($this->getUserInfoAction)($user))
            ->json();
    }

    /**
     * Self-service write on the caller's own row: bypass the CRUD `update` authorization and
     * versioning events, which gate admins editing other users.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function storeAttributes(Request $request, User $user, array $attributes): \Illuminate\Http\JsonResponse
    {
        $user->forceFill($attributes)->saveQuietly();

        return new ResponseBuilder($request)
            ->setData(($this->getUserInfoAction)($user))
            ->json();
    }
}
