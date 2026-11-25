<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Utils;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Create pages whose form edits translated fields, the sort column or guarded attributes: the record is
 * filled through {@see AssignsAttributesOwnedByTheForm} instead of mass assignment alone. A creation that
 * approvals capture is reported as sent for approval, and the page goes back to the list, since there is
 * no record yet to open.
 *
 * @phpstan-require-extends \Filament\Resources\Pages\CreateRecord
 */
trait CreatesRecordFromFormState
{
    use AssignsAttributesOwnedByTheForm;

    private bool $creationSentForApproval = false;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->stripUnsupportedFilamentFormAttributes(parent::mutateFormDataBeforeCreate($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[Override]
    protected function handleRecordCreation(array $data): Model
    {
        $record = new (self::getModel());

        $this->fillFromFormState($record, $data);
        $this->creationSentForApproval = ! $record->save();

        return $record;
    }

    #[Override]
    protected function getCreatedNotification(): ?Notification
    {
        if (! $this->creationSentForApproval) {
            return parent::getCreatedNotification();
        }

        return Notification::make()
            ->warning()
            ->title(__('app.messages.creationSentForApproval'))
            ->body(__('app.messages.creationSentForApprovalBody'));
    }

    #[Override]
    protected function getRedirectUrl(): string
    {
        return $this->creationSentForApproval ? static::getResource()::getUrl('index') : parent::getRedirectUrl();
    }
}
