<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ItemNotFoundException;
use Modules\Core\Actions\Docs\MergeSwaggerDocsAction;
use Modules\Core\Cache\CacheManager;
use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Overrides\MergedSwaggerFile;
use Wotz\SwaggerUi\Http\Controllers\OpenApiJsonController;

final class DocsController extends OpenApiJsonController
{
    public function __construct(
        private readonly MergeSwaggerDocsAction $mergeSwaggerDocsAction,
    ) {}

    /**
     * Entry point when the package's route is hit (OpenApiJsonController is bound to this class).
     * Delegates to mergeDocs so the merged spec is served with server/oauth config applied.
     */
    public function __invoke(Request $request, string $filename): JsonResponse
    {
        return $this->mergeDocs($request, $filename);
    }

    /**
     * Serves merged OpenAPI spec (from MergeSwaggerDocsAction) with server_url and oauth applied.
     */
    public function mergeDocs(Request $request, string $filename = 'v1'): JsonResponse
    {
        $index = resolve(ICacheLabelIndex::class);
        $lookup = $index->lookup(CacheManager::key('docs', 'merged', $filename), new CacheDependencies([CacheLabel::docs()]));

        if ($lookup->hit && is_array($lookup->value)) {
            $json = $lookup->value;
        } else {
            $json = ($this->mergeSwaggerDocsAction)($filename);
            $index->store($lookup, $json, (int) config('cache.duration.long'));
        }

        try {
            $file = collect(config('swagger-ui.files'))->filter(function (array $values) use ($filename, $request): bool {
                $path = implode('/', array_slice($request->segments(), 0, -1));

                return isset($values['versions'][$filename]) && $path === mb_ltrim($values['path'], '/');
            })->firstOrFail();
        } catch (ItemNotFoundException) {
            return abort(404);
        }

        $swagger_file = new MergedSwaggerFile($file['path'], $filename, $json);

        return response()->json($swagger_file->json());
    }
}
