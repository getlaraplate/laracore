<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Utils;

use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Edit pages whose form edits translated fields, the sort column or guarded attributes, for models without
 * approvals: the record is filled through {@see AssignsAttributesOwnedByTheForm} instead of mass assignment
 * alone. A model with approvals uses {@see ReportsApprovalOutcome}, which does the same and reports a
 * captured change.
 *
 * @phpstan-require-extends \Filament\Resources\Pages\EditRecord
 */
trait UpdatesRecordFromFormState
{
    use AssignsAttributesOwnedByTheForm;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->stripUnsupportedFilamentFormAttributes(parent::mutateFormDataBeforeSave($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $this->fillFromFormState($record, $data);
        $record->save();

        return $record;
    }
}
