<?php

declare(strict_types=1);

namespace Modules\Core\Overrides;

use Override;
use Wotz\SwaggerUi\SwaggerFile;

/**
 * A {@see SwaggerFile} whose content is the merged specification this application builds, instead of a single
 * file read from disk.
 *
 * `wotz/laravel-swagger-ui` v2 moved `getJson`, `configureServer` and `configureOAuth` off the controller and
 * into {@see SwaggerFile}, so overriding the controller no longer reaches them. This subclass keeps the
 * package's server and OAuth rewriting, which reads `swagger-ui.files` config, and replaces only the source of
 * the specification.
 *
 * `SwaggerFile::make()` returns `new self`, so this class must be instantiated directly.
 *
 * @see \Modules\Core\Http\Controllers\DocsController::mergeDocs()
 */
final class MergedSwaggerFile extends SwaggerFile
{
    /**
     * @param  string  $name  The `path` of the entry in `swagger-ui.files`; the parent resolves its config by it.
     * @param  array<string, mixed>  $merged  The already merged specification.
     */
    public function __construct(
        string $name,
        string $version,
        private readonly array $merged,
    ) {
        parent::__construct($name, $version);
    }

    /**
     * @return array<string, mixed>|mixed
     */
    #[Override]
    public function json(?string $key = null): mixed
    {
        if ($key !== null) {
            return data_get($this->json(), $key);
        }

        if (isset($this->json)) {
            return $this->json;
        }

        $json = $this->configureServer($this->merged);

        return $this->json = $this->configureOAuth($json);
    }
}
