<?php

declare(strict_types=1);

namespace Modules\Core\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Models\Module;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Services\Authorization\AuthorizationService;
use Override;

final class UserInfoResponse extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    #[Override]
    public function toArray(\Illuminate\Http\Request $request): array
    {
        if ($this->resource) {
            $permissions = [];

            foreach ($this->resource->isSuperAdmin() ? $this->resource->getPermissionsViaRoles() : $this->resource->getAllPermissions() as $permission) {
                $guard_key = $permission->guard_name;

                if (! isset($permissions[$guard_key])) {
                    $permissions[$guard_key] = [$permission->name];
                } else {
                    $permissions[$guard_key][] = $permission->name;
                }
            }

            $roles = $this->resource->roles->map(static fn (Role $role): mixed => $role->name);

            return [
                'id' => $this->resource->id,
                'name' => $this->resource->name,
                'username' => $this->resource->username,
                'email' => $this->resource->email,
                // The user's explicit language preference (null when unset — the
                // caller falls back to the browser / app default itself).
                'lang' => $this->resource->lang,
                // The user's IANA timezone (null when unset: the caller converts from UTC on its own).
                'timezone' => $this->resource->timezone,
                'groups' => $roles,
                'canImpersonate' => $this->resource->canImpersonate(),
                'permissions' => $permissions,
                'apps' => $this->apps($this->resource),
                // Whether the onboarding flow still applies, and the server-persisted
                // UI preferences the SPA rehydrates its chrome from.
                'isFirstLogin' => (bool) $this->resource->is_first_login,
                'preferences' => (object) ($this->resource->preferences ?? []),
            ];
        }

        return [
            'id' => 'anonymous',
            'name' => 'anonymous',
            'username' => 'anonymous',
            'email' => 'anonymous',
            'lang' => null,
            'timezone' => null,
            'groups' => [],
            'canImpersonate' => false,
            'permissions' => [],
            'apps' => [],
            'isFirstLogin' => false,
            'preferences' => (object) [],
        ];
    }

    /**
     * The frontend apps the user may open: the active modules with an address (`core_modules`), narrowed to
     * the ones the user holds a permission on, the rule that gates a scoped login. A super admin sees them
     * all. The Core module is the platform, not an app, so it never appears.
     *
     * @return list<array{slug: string, name: string, logoMark: string, url: string}>
     */
    private function apps(User $user): array
    {
        $accessible = $user->isSuperAdmin()
            ? null
            : resolve(AuthorizationService::class)->accessibleModules($user);

        return Module::query()
            ->where('is_active', true)
            ->whereNotNull('url')
            ->where('name', '!=', Module::CORE)
            ->orderBy('name')
            ->get(['name', 'url'])
            ->filter(static fn (Module $module): bool => mb_trim((string) $module->url) !== ''
                && ($accessible === null || $accessible->contains(mb_strtolower($module->name))))
            ->map(static fn (Module $module): array => [
                'slug' => mb_strtolower($module->name),
                'name' => $module->name,
                'logoMark' => mb_strtoupper(mb_substr($module->name, 0, 1)),
                'url' => mb_trim((string) $module->url),
            ])
            ->values()
            ->all();
    }
}
