<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Database\Factories\MediaDraftFactory;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Helpers\HasMedia;
use Override;
use Spatie\MediaLibrary\HasMedia as SpatieHasMedia;

/**
 * Token-keyed pending-media bucket used by CREATE forms, where the owner record
 * does not exist yet and uploads cannot bind to an id. Files are staged in the
 * single `pending` collection (each carrying a `target_collection` custom
 * property) and later moved onto the freshly created record by the claim flow.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token The client-generated token that keys the pending bucket
 * @property string $target_module The module of the owner entity the media will be claimed onto
 * @property string $target_entity The owner entity the media will be claimed onto
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class MediaDraft extends Model implements SpatieHasMedia
{
    /** @use HasFactory<MediaDraftFactory> */
    use HasFactory;
    use HasMedia;

    public const string PENDING_COLLECTION = 'pending';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'user_id',
        'token',
        'target_module',
        'target_entity',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::MediaDrafts->value;

    #[Override]
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::PENDING_COLLECTION);
    }

    protected static function newFactory(): MediaDraftFactory
    {
        return MediaDraftFactory::new();
    }
}
