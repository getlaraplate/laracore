<?php

declare(strict_types=1);

namespace Modules\Core\Http\Requests;

use Modules\Core\Casts\ListRequestData;
use Modules\Core\Rules\ClientScopeFilters;
use Modules\Core\Rules\QueryBuilder;
use Modules\Core\Support\BooleanInput;
use Override;

/**
 * @property ?int $pagination
 * @property ?int $page
 * @property ?int $from
 * @property ?int $to
 * @property ?int $limit
 * @property ?bool $count
 * @property ?bool $totals
 * @property ?array<list{property:string,direction:string}> $sort
 * @property array<int, list{property:string,value:mixed}> $filters
 * @property ?array $group_by
 */
class ListRequest extends SelectRequest
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function rules(): array
    {
        $rules = parent::rules() + [
            'pagination' => ['integer', 'numeric', 'min:1', 'exclude_if:count,true'],
            'page' => ['integer', 'numeric', 'min:1', 'exclude_if:count,true'],
            'from' => ['integer', 'numeric', 'min:1', 'exclude_if:count,true'],
            'to' => ['integer', 'numeric', 'min:1', 'exclude_if:count,true'],
            'limit' => ['integer', 'numeric', 'min:1', 'exclude_if:count,true'],
            'count' => ['boolean'],
            'totals' => ['boolean'],
            /** On a host of extenders: include the extended rows the user may read, each as its extender (XS3). */
            'with_extended' => ['boolean'],
            'sort.*.property' => ['string'],
            'sort.*.direction' => ['in:asc,desc,ASC,DESC'],
            'filters' => [new QueryBuilder(), new ClientScopeFilters($this->model)],
            'group_by.*' => ['string'],
            /** Snapshot ids for freshness presence (on_page / off_page / gone). */
            'check_ids' => ['sometimes', 'array', 'max:100'],
            'check_ids.*' => ['nullable'],
        ];
        $rules['relations.*'][] = 'exclude_if:count,true';

        return $rules;
    }

    #[Override]
    public function parsed(): ListRequestData
    {
        /** @phpstan-ignore method.notFound */
        return new ListRequestData($this, $this->resolveMainEntity(), $this->validated(), $this->primaryKey, $this->input('module'));
    }

    #[Override]
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $to_merge = [];

        $sort = $this->input('sort');

        if ($sort) {
            $to_merge['sort'] = is_string($sort) && is_json($sort) ? json_decode($sort, true) : (is_string($sort) ? preg_split("/,\s?/", $sort) : $sort);
        }

        $filters = $this->input('filters');

        if ($filters) {
            $to_merge['filters'] = is_string($filters) && is_json($filters) ? json_decode($filters, true) : $filters;
        }

        $group_by = $this->input('group_by');

        if ($group_by) {
            $to_merge['group_by'] = is_string($group_by) && is_json($group_by) ? json_decode($group_by, true) : $group_by;
        }

        $check_ids = $this->input('check_ids');

        if (is_string($check_ids) && is_json($check_ids)) {
            $to_merge['check_ids'] = json_decode($check_ids, true);
        }

        foreach (['count', 'totals'] as $flag) {
            if ($this->exists($flag)) {
                $to_merge[$flag] = BooleanInput::coerce($this->input($flag));
            }
        }

        /** @phpstan-ignore method.notFound */
        $this->merge($to_merge);
    }
}
