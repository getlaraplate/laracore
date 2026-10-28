<?php

declare(strict_types=1);
use Illuminate\Database\Eloquent\Model;

return [
    'name' => 'Core',

    /**
     * Trusted connection overrides for Core models that may be stored outside
     * the application's default database.
     *
     * Frozen: native modules share one schema on one connection. Add no
     * entries. See docs/database-connection-affinity-audit.md.
     *
     * @var array<class-string<Model>, string>
     */
    'model_connections' => [],

    /**
     * Per-minute rate limits for Core-defined job queues (see RouteServiceProvider).
     * `embeddings` throttles calls to the embedding service; keep it low when the
     * service saturates under load, raise it for faster bulk backfills.
     */
    'queue_rate_limits' => [
        // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'embeddings' => (int) env('EMBEDDINGS_QUEUE_RATE_PER_MINUTE', 10),
    ],

    /**
     * Ceiling for how many models a bulk (scout:import) reindex writes to the
     * search engine per request. The adaptive batcher starts here and shrinks
     * when the engine strains, so this only caps the optimistic batch size.
     */
    // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
    'bulk_index_batch' => (int) env('BULK_INDEX_BATCH', 100),

    /**
     * optimistic locking table column.
     */
    'locking' => [
        // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'lock_version_column' => env('LOCKIN_LOCK_VERSION_COLUMN', 'lock_version'),
        // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'lock_at_column' => env('LOCKIN_LOCK_AT_COLUMN', 'locked_at'),
        // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'lock_by_column' => env('LOCKIN_LOCK_BY_COLUMN', 'locked_user_id'),
        // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'lock_until_column' => env('LOCKIN_LOCK_UNTIL_COLUMN', 'locked_until'),
        // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'lease_ttl' => (int) env('LOCKING_LEASE_TTL', 900),
        // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'can_be_unlocked' => explode(',', (string) env('LOCKING_CAN_BE_UNLOCKED', '')),
    ],

    // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
    'force_https' => env('FORCE_HTTPS', false),

    /**
     * Master switch of every /api route, Core and modules. A runtime setting (`expose_api`)
     * overrides this default; off, no API route answers.
     */
    'expose_api' => false,

    'api' => [
        /**
         * Requests a minute per token, or per client address when there is no token.
         */
        'rate_limit_per_minute' => 600,
    ],

    /**
     * Ceilings of the aggregate route and of the assistant tool built on it. A value below 1 or not numeric is
     * replaced by its default (see AggregateLimits).
     */
    'aggregate' => [
        /**
         * Measures one request may compute.
         */
        // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'max_measures' => env('CORE_AGGREGATE_MAX_MEASURES', 3),

        /**
         * Groups one request may return.
         */
        // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'max_groups' => env('CORE_AGGREGATE_MAX_GROUPS', 50),

        /**
         * Points one result may hold: a bucketed period gives one point per bucket.
         */
        // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'max_points' => env('CORE_AGGREGATE_MAX_POINTS', 400),

        /**
         * Longest period one request may span, in days.
         */
        // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'max_period_days' => env('CORE_AGGREGATE_MAX_PERIOD_DAYS', 1096),

        /**
         * Objects (widgets) the assistant may show in one message.
         */
        // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'max_objects_per_message' => env('CORE_AGGREGATE_MAX_OBJECTS_PER_MESSAGE', 6),

        /**
         * Aggregate requests a minute per user.
         */
        // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'rate_per_minute' => env('CORE_AGGREGATE_RATE_PER_MINUTE', 60),
    ],

    'api_tokens' => [
        /**
         * Longest life of a personal access token, in days.
         */
        'max_lifetime_days' => 365,
    ],

    /*
     * How near an expiry has to be for HasValidity's `expiring` scope to report a
     * record, when the caller names no window of its own. Hours, because the right
     * answer differs by model: a task expires in days, a licence in months, so a
     * model can override it with `protected static int $expiring_within_hours`.
     */
    'validity' => [
        'expiring_within_hours' => 48,
    ],

    'extended_class_suffix' => '_extended',

    'editor' => env('APP_EDITOR', 'VSCode'),

    'filament' => [
        'tabs_counts_ttl_seconds' => 60,
    ],

    'media' => [
        /**
         * Time-to-live, in hours, for pending media drafts (the token-keyed bucket
         * that backs CREATE forms). Drafts older than this are pruned, together with
         * their staged media, by the core:prune-media-drafts command.
         */
        'draft_ttl_hours' => 24,

        /**
         * Minutes a temporary URL of the API media projection stays valid, on a disk whose driver can sign URLs.
         */
        'signed_url_ttl_minutes' => 60,
    ],

    'acl' => [
        /**
         * Maximum number of users for targeted ACL cache invalidation.
         * When the number of users with a given permission exceeds this threshold,
         * the service falls back to flushing all ACL-prefixed cache keys instead
         * of deleting individual user-scoped entries.
         */
        'clear_threshold' => 500,
    ],

    'cache' => [
        /**
         * When true, the cache warming command (cache:warm) is executed automatically
         * during application boot via $app->booted(), after all service providers
         * have been registered. Useful for reducing cold-cache penalties after deployment.
         *
         * Defaults to false to avoid unexpected DB queries on every boot.
         */
        'warm_on_boot' => false,
    ],

    // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
    'deepl_api_key' => env('DEEPL_API_KEY'),

    /**
     * Notification settings for pending approvals.
     * Sends notifications to admins when records are waiting for moderation.
     */
    'notifications' => [
        'approvals' => [
            'recipients' => [
                'roles' => ['admin', 'superadmin'],
            ],
        ],
    ],
];
