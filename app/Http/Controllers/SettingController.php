<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;
use Modules\Core\Actions\Settings\GetSiteConfigsAction;
use Modules\Core\Actions\Settings\GetTranslationsAction;
use Modules\Core\Actions\Settings\GetVersionInfoAction;
use Modules\Core\Cache\CacheManager;
use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Contracts\ICacheLabelIndex;
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
        $index = resolve(ICacheLabelIndex::class);
        $lookup = $index->lookup(
            CacheManager::key('translations', (string) RequestFacade::route()->getName(), (string) $lang, json_encode($request->validated()) ?: ''),
            new CacheDependencies([CacheLabel::translations()]),
        );

        if ($lookup->hit && is_array($lookup->value)) {
            $translations = $lookup->value;
        } else {
            $selectedLang = (in_array($lang, [null, '', '0'], true)) ? null : mb_substr($lang, 0, 2);
            $translations = ($this->getTranslationsAction)($selectedLang);
            $index->store($lookup, $translations, (int) config('cache.duration.long'));
        }

        return new ResponseBuilder($request)
            ->setData($translations)
            ->json();
    }

    public function getSiteConfigs(Request $request): \Illuminate\Http\JsonResponse
    {
        $index = resolve(ICacheLabelIndex::class);
        $lookup = $index->lookup(
            CacheManager::key('settings', 'site_configs', (string) RequestFacade::route()->getName()),
            new CacheDependencies([CacheLabel::table(CoreTables::Settings->value)]),
        );

        if ($lookup->hit && is_array($lookup->value)) {
            $settings = $lookup->value;
        } else {
            $settings = ($this->getSiteConfigsAction)();
            $index->store($lookup, $settings, (int) config('cache.duration.long'));
        }

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
