<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;
use Modules\Core\Actions\Settings\GetSiteConfigsAction;
use Modules\Core\Actions\Settings\GetTranslationsAction;
use Modules\Core\Actions\Settings\GetVersionInfoAction;
use Modules\Core\Cache\CacheManager;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Cache\Labels\LabelledCache;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Helpers\ResponseBuilder;
use Modules\Core\Http\Requests\TranslationsRequest;

final class SettingController extends Controller
{
    public function __construct(
        private readonly GetTranslationsAction $getTranslationsAction,
        private readonly GetSiteConfigsAction $getSiteConfigsAction,
        private readonly GetVersionInfoAction $getVersionInfoAction,
    ) {}

    public function getTranslations(TranslationsRequest $request, ?string $lang = null): \Illuminate\Http\JsonResponse
    {
        $translations = resolve(LabelledCache::class)->remember(
            CacheManager::key('translations', (string) RequestFacade::route()->getName(), (string) $lang, json_encode($request->validated()) ?: ''),
            [CacheLabel::translations()],
            (int) config('cache.duration.long'),
            function () use ($lang): array {
                $selectedLang = (in_array($lang, [null, '', '0'], true)) ? null : mb_substr($lang, 0, 2);

                return ($this->getTranslationsAction)($selectedLang);
            },
            is_array(...),
        );

        return new ResponseBuilder($request)
            ->setData($translations)
            ->json();
    }

    public function getSiteConfigs(Request $request): \Illuminate\Http\JsonResponse
    {
        $settings = resolve(LabelledCache::class)->remember(
            CacheManager::key('settings', 'site_configs', (string) RequestFacade::route()->getName()),
            [CacheLabel::table(CoreTables::Settings->value)],
            (int) config('cache.duration.long'),
            fn (): array => ($this->getSiteConfigsAction)(),
            is_array(...),
        );

        return new ResponseBuilder($request)
            ->setData($settings)
            ->json();
    }

    public function siteInfo(Request $request): \Illuminate\Http\JsonResponse
    {
        return new ResponseBuilder($request)
            ->setData([
                'name' => config('app.name'),
            ] + ($this->getVersionInfoAction)())
            ->json();
    }
}
