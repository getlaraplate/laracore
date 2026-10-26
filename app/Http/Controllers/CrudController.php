<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers;

use BadMethodCallException;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordsNotFoundException as DatabaseRecordsNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Modules\Core\Approvals\PendingDeletionLock;
use Modules\Core\Enums\AggregateErrorCode;
use Modules\Core\Exceptions\AggregateException;
use Modules\Core\Exceptions\CrudWriteNotAllowedException;
use Modules\Core\Helpers\ResponseBuilder;
use Modules\Core\Http\Requests\AggregateRequest;
use Modules\Core\Http\Requests\CrudRequest;
use Modules\Core\Http\Requests\DetailRequest;
use Modules\Core\Http\Requests\DomainActionRequest;
use Modules\Core\Http\Requests\FacetsRequest;
use Modules\Core\Http\Requests\HistoryRequest;
use Modules\Core\Http\Requests\LatestDisapprovalRequest;
use Modules\Core\Http\Requests\ListRequest;
use Modules\Core\Http\Requests\ModifyRequest;
use Modules\Core\Http\Requests\PendingApprovalsRequest;
use Modules\Core\Http\Requests\SearchRequest;
use Modules\Core\Http\Requests\TreeRequest;
use Modules\Core\Locking\Exceptions\CannotUnlockException;
use Modules\Core\Locking\Exceptions\LockedModelException;
use Modules\Core\Locking\Exceptions\MissingLockVersionException;
use Modules\Core\Locking\Exceptions\StaleModelLockingException;
use Modules\Core\Models\User;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Services\Crud\DomainActionDispatcher;
use Modules\Core\Services\Crud\DTOs\CrudResult;
use Modules\Core\Services\Crud\DTOs\FacetQuery;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use UnexpectedValueException;

class CrudController extends Controller
{
    public function __construct(private readonly CrudService $crudService) {}

    final public function list(ListRequest $request): Response
    {
        $requestData = $request->parsed();

        return $this->handleServiceCall(
            fn (): CrudResult => $this->crudService->list($requestData),
            $request,
            $requestData->model,
        );
    }

    /**
     * Standalone facet counts for the entity, independent of the data list so the
     * client can reload facets without re-fetching table rows. Every requested column
     * is a facet dimension.
     */
    final public function facets(FacetsRequest $request): Response
    {
        // Routed through the shared mapping like every other operation. Its own `try` caught only
        // AuthorizationException, so an invalid facet escaped to the global handler as a 500 and the
        // message explaining what was wrong with it never reached the caller. The success payload is
        // unchanged: buildResponse sets the data and nothing else when there is no meta.
        return $this->handleServiceCall(
            function () use ($request): CrudResult {
                $facet = $request->facet();

                return new CrudResult(
                    data: $facet instanceof FacetQuery
                        ? $this->crudService->facetValues($request->parsed(), $facet)->toArray()
                        : $this->crudService->facetCounts($request->parsed()),
                );
            },
            $request,
            shouldCache: false,
        );
    }

    /**
     * The figures of an aggregate request: groups, totals and periods, computed under the permissions of the list.
     *
     * It does not go through {@see handleServiceCall()}: that mapping answers a database failure with the text of
     * the exception, which holds the SQL, and an aggregate answers a failure it did not foresee with a message of
     * its own. Never cached, because the figures follow the rows.
     *
     * Answers: 200 `{data, meta}`; 422 `{error: {code, message, field?}}` for a request the contract refuses, reported
     * when the refusal is a fault of the deployment and logged as a notice when it comes from an invalid argument;
     * the status of a refused permission; 500 `{error: {message}}`, reported, for anything else.
     */
    final public function aggregate(AggregateRequest $request): Response
    {
        try {
            return new JsonResponse($this->crudService->aggregate($request->parsed(), $request->aggregateQuery())->toArray());
        } catch (AggregateException $exception) {
            // The refusal decides for itself: a client's mistake stays out of the log, a fault of the deployment goes in.
            report($exception);

            return $exception->toResponse($request);
        } catch (AuthorizationException $exception) {
            return $this->buildResponse(
                new CrudResult(
                    data: null,
                    error: $exception->getMessage(),
                    statusCode: $this->authorizationFailureStatus($request),
                ),
                $request,
            );
        } catch (ValidationException $exception) {
            // The list data of the request refuses what the validation let through, a malformed scope filter.
            $field = array_key_first($exception->errors());

            return $this->aggregateRefusal($request, $exception->getMessage(), $field === null ? null : (string) $field);
        } catch (InvalidArgumentException $exception) {
            // The guards of the list and of its filters: their message names fields, never SQL. The framework and
            // Carbon throw this type too, so every such refusal is logged, for an unexpected origin to show.
            Log::notice('Aggregate request refused', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'module' => $request->route('module'),
                'entity' => $request->route('entity'),
            ]);

            return $this->aggregateRefusal($request, $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return new JsonResponse(['error' => ['message' => 'The aggregate could not be computed.']], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Lightweight list fingerprint (id + updated_at) for freshness ping.
     *
     * Same auth, ACL, filters, sort and pagination as {@see list()}; never cached.
     */
    final public function freshness(ListRequest $request): Response
    {
        $requestData = $request->parsed();

        return $this->handleServiceCall(
            fn (): CrudResult => $this->crudService->freshness($requestData),
            $request,
            $requestData->model,
            shouldCache: false,
        );
    }

    /**
     * Show the specified resource.
     *
     * @throws BindingResolutionException
     * @throws Throwable
     * @throws UnexpectedValueException
     */
    final public function detail(DetailRequest $request): Response
    {
        $requestData = $request->parsed();

        return $this->handleServiceCall(
            fn (): CrudResult => $this->crudService->detail($requestData),
            $request,
            $requestData->model,
        );
    }

    public function search(SearchRequest $request): Response
    {
        $requestData = $request->parsed();

        return $this->handleServiceCall(
            fn (): CrudResult => $this->crudService->search($requestData),
            $request,
            $requestData->model,
            shouldCache: false,
        );
    }

    final public function history(HistoryRequest $request): Response
    {
        $requestData = $request->parsed();

        return $this->handleServiceCall(
            fn (): CrudResult => $this->crudService->history($requestData),
            $request,
            $requestData->model,
        );
    }

    final public function tree(TreeRequest $request): Response
    {
        $requestData = $request->parsed();

        return $this->handleServiceCall(
            fn (): CrudResult => $this->crudService->tree($requestData),
            $request,
            $requestData->model,
        );
    }

    final public function insert(ModifyRequest $request): Response
    {
        $requestData = $request->parsed();

        try {
            $result = $this->crudService->insert($requestData);

            // Invalidate cache after insert
            Cache::clearByEntity($requestData->model);

            return $this->buildResponse($result, $request);
        } catch (Throwable $ex) {
            return $this->handleServiceCall(fn () => throw $ex, $request, $requestData->model, shouldCache: false);
        }
    }

    final public function update(ModifyRequest $request): Response
    {
        $requestData = $request->parsed();

        try {
            $result = $this->crudService->update($requestData);

            // Invalidate cache after update
            Cache::clearByEntity($requestData->model);

            return $this->buildResponse($result, $request);
        } catch (Throwable $ex) {
            return $this->handleServiceCall(fn () => throw $ex, $request, $requestData->model, shouldCache: false);
        }
    }

    final public function delete(ModifyRequest $request): Response
    {
        $requestData = $request->parsed();

        try {
            $result = $this->crudService->delete($requestData);

            // Invalidate cache after delete
            Cache::clearByEntity($requestData->model);

            return $this->buildResponse($result, $request);
        } catch (Throwable $ex) {
            return $this->handleServiceCall(fn () => throw $ex, $request, $requestData->model, shouldCache: false);
        }
    }

    /**
     * @param  "activate"|"inactivate"  $operation
     *
     * @throws UnexpectedValueException
     * @throws Exception
     * @throws BindingResolutionException
     * @throws Throwable
     */
    final public function doActivateOperation(ModifyRequest $request, string $operation): Response
    {
        $requestData = $request->parsed();

        try {
            $result = $this->crudService->doActivateOperation($requestData, $operation);

            // Invalidate cache after activate/inactivate
            Cache::clearByEntity($requestData->model);

            return $this->buildResponse($result, $request);
        } catch (Throwable $ex) {
            return $this->handleServiceCall(fn () => throw $ex, $request, $requestData->model, shouldCache: false);
        }
    }

    final public function activate(ModifyRequest $request): Response
    {
        return $this->doActivateOperation($request, 'activate');
    }

    final public function inactivate(ModifyRequest $request): Response
    {
        return $this->doActivateOperation($request, 'inactivate');
    }

    final public function approve(ModifyRequest $request): Response
    {
        $requestData = $request->parsed();

        try {
            $result = $this->crudService->approve($requestData);

            // Invalidate cache after approve
            Cache::clearByEntity($requestData->model);

            return $this->buildResponse($result, $request);
        } catch (Throwable $ex) {
            return $this->handleServiceCall(fn () => throw $ex, $request, $requestData->model, shouldCache: false);
        }
    }

    final public function disapprove(ModifyRequest $request): Response
    {
        $requestData = $request->parsed();

        try {
            $result = $this->crudService->disapprove($requestData);

            // Invalidate cache after disapprove
            Cache::clearByEntity($requestData->model);

            return $this->buildResponse($result, $request);
        } catch (Throwable $ex) {
            return $this->handleServiceCall(fn () => throw $ex, $request, $requestData->model, shouldCache: false);
        }
    }

    /**
     * The author withdraws a request before its decision. A decided request cannot be
     * withdrawn any more: that clashes with its current state, hence 409.
     */
    final public function withdraw(ModifyRequest $request): Response
    {
        $requestData = $request->parsed();

        try {
            return $this->buildResponse($this->crudService->withdraw($requestData), $request);
        } catch (LogicException $ex) {
            // Only the bare class: its subclasses (invalid argument, domain rule, bad method)
            // keep the answers handleServiceCall() gives them.
            if ($ex::class === LogicException::class) {
                return $this->buildResponse(
                    new CrudResult(
                        data: null,
                        error: $ex->getMessage(),
                        statusCode: Response::HTTP_CONFLICT,
                    ),
                    $request,
                );
            }

            return $this->handleServiceCall(fn () => throw $ex, $request, $requestData->model, shouldCache: false);
        } catch (Throwable $ex) {
            return $this->handleServiceCall(fn () => throw $ex, $request, $requestData->model, shouldCache: false);
        }
    }

    /**
     * List active modifications awaiting approval for the given entity.
     */
    final public function pendingApprovals(PendingApprovalsRequest $request): Response
    {
        $requestData = $request->parsed();

        return $this->handleServiceCall(
            fn (): CrudResult => $this->crudService->pendingApprovals($requestData),
            $request,
            $requestData->model,
            shouldCache: false,
        );
    }

    /**
     * Every pending request the current user can vote on, on any entity, plus their own.
     */
    final public function allPendingApprovals(Request $request): Response
    {
        return $this->handleServiceCall(
            function () use ($request): CrudResult {
                $user = $request->user();
                throw_unless($user instanceof User, AuthorizationException::class, 'Authenticated user is required.');

                return $this->crudService->allPendingApprovals($user);
            },
            $request,
            shouldCache: false,
        );
    }

    /**
     * Latest soft-kept disapproval for the current user as modifier of a record.
     */
    final public function latestDisapproval(LatestDisapprovalRequest $request): Response
    {
        $requestData = $request->parsed();

        return $this->handleServiceCall(
            fn (): CrudResult => $this->crudService->latestDisapproval($requestData),
            $request,
            $requestData->model,
            shouldCache: false,
        );
    }

    final public function lock(ModifyRequest $request): Response
    {
        $requestData = $request->parsed();

        try {
            $result = $this->crudService->lock($requestData);

            // Invalidate cache after lock
            Cache::clearByEntity($requestData->model);

            return $this->buildResponse($result, $request);
        } catch (Throwable $ex) {
            return $this->handleServiceCall(fn () => throw $ex, $request, $requestData->model, shouldCache: false);
        }
    }

    final public function unlock(ModifyRequest $request): Response
    {
        $requestData = $request->parsed();

        try {
            $result = $this->crudService->unlock($requestData);

            // Invalidate cache after unlock
            Cache::clearByEntity($requestData->model);

            return $this->buildResponse($result, $request);
        } catch (Throwable $ex) {
            return $this->handleServiceCall(fn () => throw $ex, $request, $requestData->model, shouldCache: false);
        }
    }

    final public function clearModelCache(CrudRequest $request): Response
    {
        $requestData = $request->parsed();
        $result = $this->crudService->clearModelCache($requestData);

        return $this->buildResponse($result, $request);
    }

    /**
     * Invoke a module-registered domain action on one record.
     *
     * The dispatcher arrives by method injection so the constructor stays a
     * single CrudService dependency (no constructor coupling for collaborators
     * that reuse list() via composition).
     *
     * A handler returning a Response is returned untouched — that is how file
     * exports stream. Anything else is wrapped in a CrudResult, so a domain
     * action looks like every other CRUD response.
     */
    final public function domainAction(DomainActionRequest $request, DomainActionDispatcher $dispatcher): Response
    {
        $requestData = $request->parsed();
        $model = $requestData->model;

        try {
            $record = $model->newQuery()->whereKey($request->input('id'))->firstOrFail();

            /** @var User $user */
            $user = $request->user();

            $result = $dispatcher->dispatch($record, $request->action(), $user, $request->payload());

            if ($result instanceof Response) {
                return $result;
            }

            Cache::clearByEntity($model);

            return $this->buildResponse(new CrudResult(data: $result), $request);
        } catch (Throwable $ex) {
            return $this->handleServiceCall(fn () => throw $ex, $request, $model, shouldCache: false);
        }
    }

    /**
     * A refusal of the aggregate that has no contract code of its own: a field the request names wrongly.
     */
    private function aggregateRefusal(Request $request, string $message, ?string $field = null): Response
    {
        return (new AggregateException(AggregateErrorCode::InvalidField, $message, $field))->toResponse($request);
    }

    /**
     * Build HTTP Response from CrudResult.
     */
    private function buildResponse(CrudResult $result, Request $request): Response
    {
        $builder = new ResponseBuilder($request);
        $builder->setData($result->data);

        if ($result->meta instanceof \Modules\Core\Services\Crud\DTOs\CrudMeta) {
            if ($result->meta->totalRecords !== null) {
                $builder->setTotalRecords($result->meta->totalRecords);
            }

            if ($result->meta->currentRecords !== null) {
                $builder->setCurrentRecords($result->meta->currentRecords);
            }

            if ($result->meta->currentPage !== null) {
                $builder->setCurrentPage($result->meta->currentPage);
            }

            if ($result->meta->totalPages !== null) {
                $builder->setTotalPages($result->meta->totalPages);
            }

            if ($result->meta->pagination !== null) {
                $builder->setPagination($result->meta->pagination);
            }

            if ($result->meta->from !== null) {
                $builder->setFrom($result->meta->from);
            }

            if ($result->meta->to !== null) {
                $builder->setTo($result->meta->to);
            }

            if ($result->meta->hasMore !== null) {
                $builder->setHasMore($result->meta->hasMore);
            }

            if ($result->meta->mode !== null) {
                $builder->setMode($result->meta->mode);
            }

            if ($result->meta->class !== null) {
                $builder->setClass($result->meta->class);
            }

            if ($result->meta->table !== null) {
                $builder->setTable($result->meta->table);
            }

            if ($result->meta->cachedAt instanceof \Illuminate\Support\Carbon) {
                $builder->setCachedAt($result->meta->cachedAt);
            }

            if ($result->meta->relations !== []) {
                $builder->setRelationsMeta($result->meta->relations);
            }

            if (is_array($result->meta->search['search'] ?? null)) {
                $builder->setSearchMeta($result->meta->search['search']);
            }
        }

        if ($result->error) {
            $builder->setError($result->error);
        }

        if ($result->statusCode) {
            $builder->setStatus($result->statusCode);
        }

        return $builder->getResponse();
    }

    /**
     * Handle service call with error handling and optional caching.
     */
    private function handleServiceCall(callable $serviceCall, Request $request, ?Model $model = null, bool $shouldCache = true): Response
    {
        try {
            $result = $serviceCall();

            // Handle cache for read operations
            if ($model && $shouldCache && $this->shouldCache($request)) {
                return Cache::tryByRequest($model, $request, fn (): Response => $this->buildResponse($result, $request));
            }

            return $this->buildResponse($result, $request);
        } catch (QueryException $ex) {
            return $this->buildResponse(
                new CrudResult(
                    data: null,
                    error: $ex->getMessage(),
                    statusCode: Response::HTTP_INTERNAL_SERVER_ERROR,
                ),
                $request,
            );
        } catch (PendingDeletionLock $ex) {
            // The record's deletion waits for approval, and a Block model refuses changes until
            // the decision: the write clashes with the record's current state.
            return $this->buildResponse(
                new CrudResult(
                    data: null,
                    error: $ex->getMessage(),
                    statusCode: Response::HTTP_CONFLICT,
                ),
                $request,
            );
        } catch (LockedModelException $ex) {
            return $this->buildResponse(
                new CrudResult(
                    data: null,
                    error: $ex->getMessage(),
                    statusCode: Response::HTTP_LOCKED,
                ),
                $request,
            );
        } catch (UnexpectedValueException|BadMethodCallException|InvalidArgumentException $ex) {
            return $this->buildResponse(
                new CrudResult(
                    data: null,
                    error: $ex->getMessage(),
                    statusCode: Response::HTTP_BAD_REQUEST,
                ),
                $request,
            );
        } catch (ValidationException $ex) {
            return $this->buildResponse(
                new CrudResult(
                    data: null,
                    error: $ex->getMessage(),
                    statusCode: Response::HTTP_UNPROCESSABLE_ENTITY,
                ),
                $request,
            );
        } catch (StaleModelLockingException $ex) {
            // Ordinary concurrent editing: somebody saved the record between the moment this client
            // read it and the moment it wrote. The textbook conflict, and until now a reported 500.
            return $this->buildResponse(
                new CrudResult(
                    data: null,
                    error: $ex->getMessage(),
                    statusCode: Response::HTTP_CONFLICT,
                ),
                $request,
            );
        } catch (MissingLockVersionException $ex) {
            // The caller did not send the version, or asked for a partial select that left it out.
            // The request is malformed rather than in conflict with anything.
            return $this->buildResponse(
                new CrudResult(
                    data: null,
                    error: $ex->getMessage(),
                    statusCode: Response::HTTP_BAD_REQUEST,
                ),
                $request,
            );
        } catch (CannotUnlockException $ex) {
            // Not "occupied" but "not unlockable here": the deployment declares this class as one
            // whose locks nobody may lift. Retrying, or waiting, or holding another permission will
            // never change the answer, so it is 403 rather than the 423 that means "somebody else
            // holds it" or the 409 that means "your request clashes with the current state".
            return $this->buildResponse(
                new CrudResult(
                    data: null,
                    error: $ex->getMessage(),
                    statusCode: Response::HTTP_FORBIDDEN,
                ),
                $request,
            );
        } catch (DomainException $ex) {
            // The request clashes with the record's current state rather than being malformed: a
            // domain rule refuses it. 409 carries a body, so the reason reaches the client.
            return $this->buildResponse(
                new CrudResult(
                    data: null,
                    error: $ex->getMessage(),
                    statusCode: Response::HTTP_CONFLICT,
                ),
                $request,
            );
        } catch (MultipleRecordsFoundException $ex) {
            // `sole()` throws this when the criteria match more than one row. It is a
            // sibling of RecordsNotFoundException rather than a subclass, so without its
            // own arm it falls to the Throwable catch below and is reported as a server
            // fault. It is not one: the request is well formed, it just fails to identify
            // a single record, so it answers 400 and stays out of the error log. 404 would
            // be wrong here, since it would tell the client nothing exists when several do.
            return $this->buildResponse(
                new CrudResult(
                    data: null,
                    error: sprintf('The criteria matched %d records, this endpoint returns a single one.', $ex->getCount()),
                    statusCode: Response::HTTP_BAD_REQUEST,
                ),
                $request,
            );
        } catch (DatabaseRecordsNotFoundException|ModelNotFoundException $ex) {
            return $this->buildResponse(
                new CrudResult(
                    data: null,
                    error: $ex->getMessage(),
                    statusCode: Response::HTTP_NOT_FOUND,
                ),
                $request,
            );
        } catch (AuthorizationException $ex) {
            return $this->buildResponse(
                new CrudResult(
                    data: null,
                    error: $ex->getMessage(),
                    statusCode: $this->authorizationFailureStatus($request),
                ),
                $request,
            );
        } catch (CrudWriteNotAllowedException $ex) {
            return $this->buildResponse(
                new CrudResult(
                    data: null,
                    error: $ex->getMessage(),
                    statusCode: Response::HTTP_FORBIDDEN,
                ),
                $request,
            );
        } catch (Throwable $ex) {
            report($ex);

            return $this->buildResponse(
                new CrudResult(
                    data: null,
                    error: $ex->getMessage(),
                    statusCode: Response::HTTP_INTERNAL_SERVER_ERROR,
                ),
                $request,
            );
        }
    }

    /**
     * Determine if request should be cached.
     */
    private function shouldCache(Request $request): bool
    {
        // Cache is enabled by default, can be disabled via query parameter
        return ! $request->boolean('no_cache');
    }
}
