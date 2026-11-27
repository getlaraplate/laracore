<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Core\Database\Factories\ModelEmbeddingFactory;
use Modules\Core\Enums\CoreTables;
use Override;

/**
 * One vector of a model: the model it belongs to, the translation it was derived from and the embedding
 * profile that produced it.
 *
 * @property int $id
 * @property string $model_type
 * @property int|string $model_id
 * @property mixed $embedding
 * @property string|null $locale
 * @property string|null $model_key
 * @property string|null $content_hash
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class ModelEmbedding extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    #[Override]
    protected $fillable = [
        'embedding',
        'locale',
        'model_key',
        'content_hash',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::ModelEmbeddings->value;

    /**
     * The model that belongs to the embedding.
     *
     * @return MorphTo<Model>
     */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function newFactory(): ModelEmbeddingFactory
    {
        return ModelEmbeddingFactory::new();
    }

    /**
     * Scope to filter embeddings by a specific model instance.
     * Filters on both model_type and model_id to leverage the composite morphs() index.
     *
     * @param  Builder<ModelEmbedding>  $query
     * @return Builder<ModelEmbedding>
     */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function forModel(Builder $query, Model $model): Builder
    {
        return $query
            ->where('model_type', $model->getMorphClass())
            ->where('model_id', $model->getKey());
    }

    /**
     * Scope to filter embeddings produced by a specific embedding-model profile.
     *
     * @param  Builder<ModelEmbedding>  $query
     * @return Builder<ModelEmbedding>
     */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function producedBy(Builder $query, string $modelKey): Builder
    {
        return $query->where('model_key', $modelKey);
    }

    /**
     * Scope to filter embeddings by the translation locale they were derived from.
     * A null locale matches non-translated models (rows with a null locale column).
     *
     * @param  Builder<ModelEmbedding>  $query
     * @return Builder<ModelEmbedding>
     */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function forLocale(Builder $query, ?string $locale): Builder
    {
        return $locale === null ? $query->whereNull('locale') : $query->where('locale', $locale);
    }

    protected function casts(): array
    {
        return [
            'embedding' => 'json',
        ];
    }
}
