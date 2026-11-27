<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Contracts\ITranslatableModel;
use Modules\Core\Models\Concerns\HasTranslations;
use Modules\Core\Services\Translation\Definitions\ITranslated;

class FakeTranslatableModel extends Model implements ITranslatableModel
{
    use HasFactory;
    use HasTranslations;

    protected $table = 'fake_translatable_models';

    protected $fillable = [
        'title',
        'slug',
        'components',
    ];

    protected $casts = [
        'components' => 'array',
    ];

    /**
     * Override translation model resolution to use the test fixture class.
     *
     * @return class-string<Model&ITranslated>
     */
    protected static function getTranslationModelClass(): string
    {
        return FakeTranslatableModelTranslation::class;
    }

    protected static function newFactory(): FakeTranslatableModelFactory
    {
        return FakeTranslatableModelFactory::new();
    }
}
