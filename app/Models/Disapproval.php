<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\Concerns\HasModerationMeta;
use Override;

/**
 * One vote against a captured write.
 *
 * Derived from cloudcake/laravel-approval (MIT), see NOTICES/laravel-approval.md.
 */
final class Disapproval extends Model implements IsPartOfParent
{
    use HasModerationMeta;

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::Disapprovals->value;

    /**
     * @var list<string>
     */
    #[Override]
    protected $guarded = ['id'];

    /**
     * @return MorphTo<Model, $this>
     */
    public function disapprover(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The relation to the record this one only exists inside, whose visibility it inherits.
     */
    #[Override]
    public function parentRelation(): string
    {
        return 'modification';
    }

    /**
     * @return BelongsTo<Modification, $this>
     */
    public function modification(): BelongsTo
    {
        return $this->belongsTo(Modification::class);
    }
}
