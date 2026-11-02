<?php

declare(strict_types=1);

namespace Modules\Core\Enums;

use Modules\Core\Enums\Concerns\HasModuleTablesUtils;

enum CoreTables: string
{
    use HasModuleTablesUtils;

    // core models
    case Entities = 'core_entities';
    case Taxonomies = 'core_taxonomies';
    case Places = 'core_places';
    case Licenses = 'core_licenses';
    case Modules = 'core_modules';
    case CronJobs = 'core_cron_jobs';
    case Templates = 'core_templates';
    case Presets = 'core_presets';
    case Fields = 'core_fields';
    case Acls = 'core_acls';
    case Settings = 'core_settings';
    case ModelEmbeddings = 'core_model_embeddings';
    case RecordOrigins = 'core_record_origins';
    case OutboxEvents = 'core_outbox_events';
    case SeedRuns = 'core_seed_runs';
    case MediaDrafts = 'core_media_drafts';
    case ImportSessions = 'core_import_sessions';
    case ImportRowErrors = 'core_import_row_errors';

    // Approvals: the schema came from stephenlake/laravel-approval, but the mechanism now lives in
    // Core (app/Approvals, HasApprovals, ModificationVoteService) and the package is on its way out,
    // so the tables are ours and carry the module prefix like any other Core table.
    case Modifications = 'core_modifications';
    case Approvals = 'core_approvals';
    case Disapprovals = 'core_disapprovals';

    // History: the schema came from overtrue/laravel-versionable, absorbed into Core (D14, D15).
    case VersionSets = 'core_versions_sets';
    case Versions = 'core_versions';
    case VersionsEpochs = 'core_versions_epochs';

    // generic or vendors models
    case Roles = 'vend_roles';
    case Permissions = 'vend_permissions';
    case Media = 'vend_media';

    case Passkeys = 'core_passkeys';

    // do not modify because they are used in the Laravel core environment
    case Users = 'users';
    case PasswordResetTokens = 'password_reset_tokens';
    case Sessions = 'sessions';

    // translations
    case TaxonomiesTranslations = 'core_taxonomies_translations';

    // pivots
    case Presettables = 'core_presettables';
    case Fieldables = 'core_fieldables';
    case ModelHasRoles = 'vend_model_has_roles';
    case ModelHasPermissions = 'vend_model_has_permissions';
    case RoleHasPermissions = 'vend_role_has_permissions';
}
