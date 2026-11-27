<?php

declare(strict_types=1);

namespace Modules\Core\Casts;

use Illuminate\Support\Str;
use Modules\Core\Http\Requests\ModifyRequest;

final class ModifyRequestData extends CrudRequestData
{
    public protected(set) array $changes = [];

    /**
     * Many-to-many relations to sync, keyed by relation name with a list of ids.
     * Kept out of {@see $changes} so a column update never treats it as an attribute.
     *
     * @var array<string, list<int|string>>
     */
    public protected(set) array $relations = [];

    /**
     * The membership the client loaded, per relation it syncs: the sync is refused when the related records it can
     * read are no longer these (D40).
     *
     * @var array<string, list<int|string>>
     */
    public protected(set) array $expectedRelations = [];

    public function __construct(ModifyRequest $request, string $mainEntity, array $validated, string|array $primaryKey, ?string $module = null)
    {
        parent::__construct($request, $mainEntity, $validated, $primaryKey, $module);

        foreach ($validated as $property => $value) {
            $key = Str::replaceFirst($this->mainEntity . '.', '', $property);

            if ($key === 'relations') {
                $this->relations = is_array($value) ? $value : [];

                continue;
            }

            if ($key === 'expected_relations') {
                $this->expectedRelations = is_array($value) ? $value : [];

                continue;
            }

            $this->changes[$key] = $value;
        }
    }

    /**
     * Allow read access to primary key and other fields from changes or request (e.g. id from route/query).
     */
    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->changes)) {
            return $this->changes[$name];
        }

        return $this->request->input($name) ?? $this->request->route($name);
    }
}
