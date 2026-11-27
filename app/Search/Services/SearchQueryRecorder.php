<?php

declare(strict_types=1);

namespace Modules\Core\Search\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Casts\ActionEnum;
use Modules\Core\Casts\SearchRequestData;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Models\User;
use Modules\Core\Search\Jobs\RecordSearchQueryJob;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\DTOs\CrudResult;
use Modules\Core\Support\PermissionName;

/**
 * Decides whether a search enters the registry and queues it. The criterion is the person, not the route: guests
 * and readers are recorded, editors (holders of the update permission of the searched model) are not.
 */
final readonly class SearchQueryRecorder
{
    public function __construct(
        private SearchQueryRegistry $registry,
        private AuthorizationService $authorization,
    ) {}

    public function shouldRecord(?User $user, Model $model): bool
    {
        if (! (bool) config('core.search.registry.enabled')) {
            return false;
        }

        if (! $user instanceof User) {
            return true;
        }

        return ! $this->authorization->userHolds($user, PermissionName::forModel($model, ActionEnum::Update->value));
    }

    public function recordAfterResponse(SearchRequestData $data, CrudResult $result): void
    {
        $user = $data->request->user();

        if (! $this->shouldRecord($user instanceof User ? $user : null, $data->model) || $result->error !== null) {
            return;
        }

        $locale = LocaleContext::get();
        $query = $this->registry->normalize($data->qs, $locale);

        if ($query === null) {
            return;
        }

        RecordSearchQueryJob::dispatchAfterResponse(
            $data->model->getTable(),
            $query,
            $locale,
            $result->meta->totalRecords ?? 0,
            now()->toDateString(),
        );
    }
}
