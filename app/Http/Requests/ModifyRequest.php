<?php

declare(strict_types=1);

namespace Modules\Core\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Casts\IParsableRequest;
use Modules\Core\Casts\ModifyRequestData;
use Modules\Core\Models\Concerns\HasValidations;
use Modules\Core\Support\BooleanInput;
use Override;

// use Illuminate\Foundation\Http\FormRequest;

final class ModifyRequest extends CrudRequest implements IParsableRequest
{
    /**
     * @var array<string, mixed>
     */
    private array $mergeRules = [];

    #[Override]
    public function rules(): array
    {
        return array_merge(parent::rules(), $this->mergeRules);
    }

    #[Override]
    public function parsed(): ModifyRequestData
    {
        /** @phpstan-ignore method.notFound */
        return new ModifyRequestData($this, $this->resolveMainEntity(), $this->validated(), $this->primaryKey, $this->input('module'));
    }

    #[Override]
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        // Model is resolved by CrudRequest: coerce only known boolean attributes so
        // "true"/"yes" survive Laravel's boolean rule without touching string fields.
        $this->coerceBooleanInputs();

        // Ensure primary key (e.g. id) from route/query is in request for update/delete so validated() and ModifyRequestData have it
        $pk = is_array($this->primaryKey) ? $this->primaryKey : [$this->primaryKey];

        foreach ($pk as $key) {
            $value = $this->route($key) ?? $this->input($key);

            if ($value !== null && $value !== '') {
                $this->merge([$key => $value]);
            }
        }

        $to_merge = [
            'filters' => $this->filters ?? [],
            // Many-to-many relation sync payload: { relation: [id, ...] }. Kept
            // separate from column changes; the whitelist is enforced in CrudService.
            'relations' => ['sometimes', 'array'],
            'relations.*' => ['array'],
            'relations.*.*' => ['integer'],
            // The membership the form loaded, per relation it syncs: a sync is refused when it changed (D40).
            'expected_relations' => ['sometimes', 'array'],
            'expected_relations.*' => ['array'],
            'expected_relations.*.*' => ['integer'],
        ];

        /** @phpstan-ignore method.notFound */
        $is_insert = Str::contains($this->url(), '/insert/');

        /** @phpstan-ignore method.notFound */
        $is_update = Str::contains($this->url(), '/update/');
        $is_autoincrement = $this->model->incrementing;

        // Primary key: required for update/detail/delete; omit from rules on insert with autoincrement so it is not validated
        if (! $is_autoincrement || ! $is_insert) {
            $validation = ['required'];

            if ($this->model->getKeyType() === 'int') {
                $validation[] = 'integer';
                $validation[] = 'numeric';
            }

            foreach (is_array($this->primaryKey) ? $this->primaryKey : [$this->primaryKey] as $key) {
                $to_merge[$key] = $validation;
            }
        }

        // if model has built-in validation rules, add them for validation (do not merge into input)
        /** @phpstan-ignore method.notFound */
        $is_delete = Str::contains($this->url(), '/delete/');

        // A withdrawal or a vote names the requests it acts on and writes nothing on the record
        // itself, so the model's own rules do not apply to it.
        /** @phpstan-ignore method.notFound */
        $is_withdraw = Str::contains($this->url(), '/withdraw/');

        /** @phpstan-ignore method.notFound */
        $is_vote = Str::contains($this->url(), ['/approve/', '/disapprove/']);

        if ($is_withdraw) {
            $to_merge['modification'] = ['required', 'integer'];
        }

        if ($is_vote) {
            // A pending create has no record yet, so a vote naming its modifications needs no id.
            foreach (is_array($this->primaryKey) ? $this->primaryKey : [$this->primaryKey] as $key) {
                if (isset($to_merge[$key])) {
                    $to_merge[$key] = ['required_without:modification', ...array_values(array_diff($to_merge[$key], ['required']))];
                }
            }

            // One request id or a list of them; none votes on every active request of the record.
            if ($this->has('modification') && ! is_array($this->input('modification'))) {
                $this->merge(['modification' => [$this->input('modification')]]);
            }

            $to_merge['modification'] = ['sometimes', 'array', 'min:1'];
            $to_merge['modification.*'] = ['integer', 'distinct'];
            $to_merge['reason'] = ['sometimes', 'nullable', 'string', 'max:255'];
        }

        if (class_uses_trait($this->model, HasValidations::class) && ! $is_delete && ! $is_withdraw && ! $is_vote) {
            /** @phpstan-ignore method.notFound */
            $main_entity = $this->resolveMainEntity();

            $pk_keys = is_array($this->primaryKey) ? $this->primaryKey : [$this->primaryKey];

            // `$this->model` is a blank instance, resolved from the route only to read its rules. A model's
            // update rules exclude the record from its own unique checks through its key
            // (`unique:table,name,{$this->id}`): without a key that exclusion is empty and saving a record
            // with its current unique value is rejected as already used. Give a copy the key being updated.
            $rules_model = $this->model;

            if ($is_update && ! is_array($this->primaryKey)) {
                $key_value = $this->input($this->primaryKey);

                if ($key_value !== null && $key_value !== '') {
                    $rules_model = clone $this->model;
                    $rules_model->setAttribute($this->primaryKey, $key_value);
                }
            }

            foreach ($rules_model->getOperationRules($is_insert ? 'create' : ($is_update ? 'update' : null)) as $attribute => $rule) {
                $rule_key = $attribute;

                // For update/delete skip merging model rules for primary key so we don't validate "exists" (service returns 404 when not found)
                if (($is_update || $is_delete) && in_array($rule_key, $pk_keys, true)) {
                    continue;
                }
                $existing = $to_merge[$rule_key] ?? [];
                $normalized = $this->normalizeRules($rule);

                // For update/delete, only validate non-PK fields when present (avoid requiring password/username on partial update)
                if (($is_update || $is_delete) && ! in_array($rule_key, $pk_keys, true)) {
                    $normalized = $this->mergeRulesUnique(['sometimes', ...$normalized]);
                }
                $to_merge[$rule_key] = $this->mergeRulesUnique([...(array) $existing, ...$normalized]);

                $to_merge['filters'][] = ['property' => $rule_key, 'value' => $this->input($rule_key) ?? $this->input(sprintf('%s.%s', $main_entity, $rule_key))];
            }
        }

        $this->mergeRules = $to_merge;
        $this->merge(['filters' => $this->filters ?? []]);
    }

    /**
     * Merge rule arrays and deduplicate only when all elements are scalar (string/int) so that Laravel rule objects (e.g. Password) are preserved.
     *
     * @param  array<int, mixed>  $rules
     * @return array<int, string|object>
     */
    private function mergeRulesUnique(array $rules): array
    {
        $all_scalar = ! array_filter($rules, static fn (mixed $r): bool => ! is_scalar($r));

        return array_values($all_scalar ? array_unique($rules) : $rules);
    }

    /**
     * Normalize rules so that strings containing "|" are split into separate rules (Laravel expects either a single string to split or an array of individual rules).
     *
     * @param  array<int, mixed>|string|mixed  $rule
     * @return array<int, string|object>
     */
    private function normalizeRules(array|string $rule): array
    {
        $rules = is_array($rule) ? $rule : [$rule];
        $result = [];

        foreach ($rules as $r) {
            if (is_string($r) && str_contains($r, '|')) {
                foreach (explode('|', $r) as $part) {
                    $part = mb_trim($part);

                    if ($part !== '') {
                        $result[] = $part;
                    }
                }
            } else {
                $result[] = $r;
            }
        }

        return $result;
    }

    /**
     * Rewrite recognised boolean forms on attributes the model already declares as boolean.
     */
    private function coerceBooleanInputs(): void
    {
        if (! $this->model instanceof Model) {
            return;
        }

        $attributes = BooleanInput::attributeNames($this->model);

        if ($attributes === []) {
            return;
        }

        $main_entity = $this->resolveMainEntity();
        $merge = [];

        foreach ($attributes as $attribute) {
            foreach ([$attribute, $main_entity . '.' . $attribute] as $key) {
                if (! $this->exists($key)) {
                    continue;
                }

                $merge[$key] = BooleanInput::coerce($this->input($key));
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}
