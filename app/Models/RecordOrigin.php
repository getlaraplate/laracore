<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Database\Factories\RecordOriginFactory;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\Concerns\HasValidations;
use Override;

/**
 * Tracks the provenance of any record: which external source it originates from
 * (imported entities) or a manual attribution. Also acts as the import identity
 * registry, mapping an external source id to a local record.
 *
 * @property int $id
 * @property string $referable_type
 * @property int $referable_id
 * @property string $source_key Machine key of the origin source, e.g. naxos_api
 * @property string|null $source_label Human-readable name of the origin source
 * @property string|null $external_id Identifier of the record in the origin source, null for manual origins
 * @property string|null $fingerprint
 * @property string|null $url Link to the record in the origin source
 * @property CarbonImmutable|null $source_updated_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class RecordOrigin extends Model
{
    use HasFactory;
    use HasValidations {
        getRules as private validationRules;
    }

    /**
     * Rules for the link to the record in its source, shared by manual writes and imports.
     *
     * @var list<string>
     */
    public const array URL_RULES = ['nullable', 'url', 'max:2048'];

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'referable_type',
        'referable_id',
        'source_key',
        'source_label',
        'external_id',
        'fingerprint',
        'url',
        'source_updated_at',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::RecordOrigins->value;

    /**
     * Whether a value may be stored as the link to the source.
     */
    public static function isValidUrl(mixed $url): bool
    {
        return Validator::make(['url' => $url], ['url' => self::URL_RULES])->passes();
    }

    /**
     * A manual attribution must carry a well-formed link. Imports write through
     * RecordOriginRegistry, which drops a malformed link rather than failing the record.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getRules(): array
    {
        $rules = $this->validationRules();
        $rules[self::DEFAULT_RULE] = array_merge($rules[self::DEFAULT_RULE], [
            'url' => self::URL_RULES,
        ]);

        return $rules;
    }

    /**
     * The record this origin belongs to.
     *
     * @return MorphTo<Model, $this>
     */
    public function referable(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function newFactory(): RecordOriginFactory
    {
        return RecordOriginFactory::new();
    }

    /**
     * Scope to the origin(s) of a given model instance, leveraging the composite morphs() index.
     *
     * @param  Builder<RecordOrigin>  $query
     * @return Builder<RecordOrigin>
     */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function forReferable(Builder $query, Model $model): Builder
    {
        return $query
            ->where('referable_type', $model->getMorphClass())
            ->where('referable_id', $model->getKey());
    }

    /**
     * Stored as UTC and exposed in the current application timezone, so the instant
     * survives timezone changes between import and read.
     */
    protected function sourceUpdatedAt(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value): ?CarbonImmutable => $value === null
                ? null
                : CarbonImmutable::parse($value, 'UTC')->setTimezone(config('app.timezone')),
            set: static fn (DateTimeInterface|string|null $value): ?string => match (true) {
                $value === null => null,
                $value instanceof DateTimeInterface => CarbonImmutable::instance($value)->utc()->format('Y-m-d H:i:s'),
                default => CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s'),
            },
        );
    }
}
