<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Casts\ActionEnum;
use Modules\Core\Contracts\IEmbeddableModel;
use Modules\Core\Contracts\IRestrictsReadableColumns;
use Modules\Core\Contracts\ISearchableModel;
use Modules\Core\Contracts\ISoftDeletableModel;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Media\MediaProjection;
use Modules\Core\Models\Concerns\HasVersions;
use Modules\Core\Observers\MediaLifecycleObserver;
use Modules\Core\Observers\MediaMetadataObserver;
use Modules\Core\Search\Contracts\IAuthorizesSearchRehydration;
use Modules\Core\Search\OwnerAuthorizerRegistry;
use Modules\Core\Search\SearchableContributorRegistry;
use Modules\Core\Search\Traits\Searchable;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\SoftDeletes\SoftDeletes;
use Modules\Core\Support\PermissionName;
use Override;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;
use Throwable;

/**
 * The shared media model owned by Core (the app-wide `media_model`). Any module
 * that uses {@see \Modules\Core\Helpers\HasMedia} stores rows here.
 *
 * Searchable (M5): a claimed media indexes standalone. Its display metadata lives
 * in Spatie `custom_properties` (M3a, no new columns); AI-derived text joins the
 * embeddable content and the document through the Core searchable-contributor
 * seam (M4a), so Core never references the AI module.
 *
 * @phpstan-use Searchable<Media>
 *
 * @implements IAuthorizesSearchRehydration<Media>
 */
#[ObservedBy([MediaMetadataObserver::class, MediaLifecycleObserver::class])]
final class Media extends BaseMedia implements IAuthorizesSearchRehydration, IEmbeddableModel, IRestrictsReadableColumns, ISearchableModel, ISoftDeletableModel
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;
    use HasVersions;
    use Searchable {
        Searchable::toSearchableArray as private toSearchableArrayTrait;
    }
    use SoftDeletes {
        HasVersions::delete insteadof SoftDeletes;
        HasVersions::forceDelete insteadof SoftDeletes;
        SoftDeletes::delete as protected deleteWithTheBin;
        SoftDeletes::forceDelete as protected forceDeleteWithTheBin;
    }

    /**
     * The single embeddable attribute is a computed accessor composing the Core
     * display surrogate (from `custom_properties`) with contributed AI text.
     *
     * @var list<string>
     */
    protected array $embed = ['searchable_embed_text'];

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::Media->value;

    #[Override]
    protected $appends = [
        'expires_at',
    ];

    /**
     * A media is indexed only once claimed onto a real owner (M14): while it is
     * staged, its owner is a {@see MediaDraft} and it must not enter search.
     */
    /**
     * Media hits inherit their owner's visibility (M16): the index holds no ACL, so at
     * rehydration each hit is kept only when the user may see its owner, through the
     * owner module's {@see \Modules\Core\Search\Contracts\IOwnerAuthorizer}. An owner type
     * with no registered authorizer falls back to the owner's own `select` ACL; one whose
     * visibility cannot be evaluated is dropped. The `core.media.search_visibility`
     * setting `open` turns this off for an owner-agnostic gallery.
     */
    #[Override]
    public function authorizeSearchRehydration(Builder $query): Builder
    {
        if (config('core.media.search_visibility', 'owner') === 'open') {
            return $query;
        }

        $owner_types = (clone $query)->distinct()->pluck('model_type')->filter()->values()->all();
        $visible = [];

        foreach ($owner_types as $owner_type) {
            if (! is_string($owner_type)) {
                continue;
            }

            $owners = self::visibleOwnersOf($owner_type);

            if ($owners instanceof Builder) {
                $visible[$owner_type] = $owners;
            }
        }

        return $query->where(static function (Builder $query) use ($visible): void {
            // No visible owner type at all: match nothing.
            $query->whereRaw('1 = 0');

            foreach ($visible as $owner_type => $owners) {
                $query->orWhere(static function (Builder $query) use ($owner_type, $owners): void {
                    $query->where('model_type', $owner_type)
                        ->whereIn('model_id', $owners->select($owners->getModel()->getQualifiedKeyName()));
                });
            }
        });
    }

    public function shouldBeSearchable(): bool
    {
        return $this->model_type !== (new MediaDraft())->getMorphClass();
    }

    /**
     * The vector source (M5): the Core display surrogate (description + keywords
     * from `custom_properties`) plus any AI-contributed text (transcript, idea,
     * intent) added through the contributor seam. Read by the `Searchable` trait
     * via the `$embed` list.
     */
    /**
     * The compact surrogate an owner's search document carries for this media (M9): the
     * Core display fields (description, keywords) plus the compact fields contributors add
     * to the media document (for AI: idea, intent, entities). Never the heavy tracks
     * (transcript, OCR), which contributors only add to the media's own vector.
     */
    /**
     * Reindex the single owner (M9, M19): its document carries this media's surrogate.
     * No-op for a draft owner, an owner that is not searchable, or an owner class that no
     * longer exists.
     */
    public function reindexOwner(): void
    {
        // An owner whose module was disabled or removed cannot be resolved; nothing to reindex.
        if (! class_exists($this->model_type)) {
            return;
        }

        $owner = $this->model;

        if ($owner instanceof ISearchableModel && $owner->shouldBeSearchable()) {
            $owner->searchable();
        }
    }

    public function ownerSurrogateText(): string
    {
        $custom = $this->custom_properties;
        $parts = [];

        foreach ([$custom['description'] ?? null, $custom['keywords'] ?? [], ...array_values(app(SearchableContributorRegistry::class)->fieldsFor($this))] as $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if (is_string($item) && mb_trim($item) !== '') {
                    $parts[] = mb_trim($item);
                }
            }
        }

        return implode(' ', $parts);
    }

    public function getSearchableEmbedTextAttribute(): string
    {
        $custom = $this->custom_properties;

        $parts = [];

        $description = $custom['description'] ?? null;

        if (is_string($description) && $description !== '') {
            $parts[] = $description;
        }

        $keywords = $custom['keywords'] ?? [];

        if (is_array($keywords)) {
            foreach ($keywords as $keyword) {
                if (is_string($keyword) && $keyword !== '') {
                    $parts[] = $keyword;
                }
            }
        }

        $contributed = app(SearchableContributorRegistry::class)->embeddableTextFor($this);

        if ($contributed !== '') {
            $parts[] = $contributed;
        }

        return mb_trim(implode(' ', $parts));
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $document = $this->toSearchableArrayTrait();

        $custom = $this->custom_properties;

        // Facets shipped from day one (M9) so a large index stays filterable and
        // Phase 2 needs no reindex to add the visual `track`.
        $document['mime'] = (string) $this->mime_type;
        $document['track'] = 'media';
        $document['collection'] = (string) $this->collection_name;

        $keywords = $custom['keywords'] ?? [];
        $document['keywords'] = is_array($keywords)
            ? array_values(array_filter($keywords, static fn (mixed $k): bool => is_string($k) && $k !== ''))
            : [];

        $description = $custom['description'] ?? null;
        $document['description'] = is_string($description) ? $description : '';

        return $document;
    }

    /**
     * The record as a client receives it. On the `api` guard a caller who may not update media gets the safe
     * projection (spec 7.2, R8): serializing is where it is applied, so a media loaded as a relation or read as the
     * `cover` of its owner is covered too. The `web` guard and a request with no user (console, queue, search
     * indexing) serialize the full record.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        if ($this->isProjectedForCurrentCaller()) {
            return resolve(MediaProjection::class)->project($this);
        }

        return parent::toArray();
    }

    /**
     * A caller who receives the projection may name, in a facet, a group_by key, a sort, a filter or an aggregate,
     * only the columns the projection returns as they are: any other would answer through the query what the
     * projection withholds (the original file name, the disks, the custom properties).
     *
     * @return list<string>|null
     */
    public function readableColumnsForCurrentCaller(): ?array
    {
        return $this->isProjectedForCurrentCaller() ? MediaProjection::QUERYABLE_COLUMNS : null;
    }

    protected function getExpiresAtAttribute(): ?Carbon
    {
        $expirationDays = config('core.soft_deletes.expiration_days');

        return $this->trashed() && $expirationDays ? $this->{self::getDeletedAtColumn()}->addDays($expirationDays) : null;
    }

    /**
     * Deletes through the bin inside the revision scope opened by {@see HasVersions::delete()}.
     */
    protected function deleteRecord(): ?bool
    {
        return $this->deleteWithTheBin();
    }

    /**
     * Force-deletes through the bin inside the revision scope opened by {@see HasVersions::forceDelete()}.
     */
    protected function forceDeleteRecord(): ?bool
    {
        return $this->forceDeleteWithTheBin();
    }

    /**
     * @return Builder<Model>|null
     */
    private static function visibleOwnersOf(string $owner_type): ?Builder
    {
        $authorizer = app(OwnerAuthorizerRegistry::class)->for($owner_type);

        if ($authorizer !== null) {
            return $authorizer->visibleOwners();
        }

        if (! is_subclass_of($owner_type, Model::class)) {
            return null;
        }

        try {
            /** @var Builder<Model> $owners */
            $owners = $owner_type::query();
            app(AuthorizationService::class)->applyAclFiltersToQuery($owners, PermissionName::forClass($owner_type, 'select'));

            return $owners;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether the caller of an `api` request lacks the `update` permission of media on that guard and within the
     * abilities of its token. With no authenticated user on the guard the projection applies as well: a request
     * the API middleware did not authenticate must not receive the full record.
     */
    private function isProjectedForCurrentCaller(): bool
    {
        if (Auth::getDefaultDriver() !== 'api') {
            return false;
        }

        $user = Auth::user();

        if (! $user instanceof Authenticatable) {
            return true;
        }

        // The answer is the same for every media of a request and its user, and a serialization asks it once per
        // media (more than once for a model that serializes through several traits): it is kept on the request.
        $request = request();
        $key = 'core.media_projected.' . spl_object_id($user);

        if ($request->attributes->has($key)) {
            return (bool) $request->attributes->get($key);
        }

        $duplicate = $request->duplicate();
        $duplicate->setUserResolver(static fn (): Authenticatable => $user);

        $projected = ! resolve(AuthorizationService::class)->checkPermission($duplicate, $this->getTable(), ActionEnum::Update->value, $this->getConnectionName());
        $request->attributes->set($key, $projected);

        return $projected;
    }
}
