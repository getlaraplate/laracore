<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Utils;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Approvals\Operation;
use Modules\Core\Approvals\PendingDeletionLock;
use Override;

/**
 * Edit pages of models with approvals: a save, delete, force delete or restore that is
 * captured says so instead of reporting a write that did not happen.
 */
trait ReportsApprovalOutcome
{
    use AssignsAttributesOwnedByTheForm;

    private bool $sentForApproval = false;

    public static function approvalAwareDeleteAction(): DeleteAction
    {
        return self::reportingApprovalOutcome(
            DeleteAction::make(),
            Operation::Delete,
            'Request deletion',
            'Deletion sent for approval',
            static fn (Model $record): ?bool => $record->delete(),
        );
    }

    public static function approvalAwareForceDeleteAction(): ForceDeleteAction
    {
        return self::reportingApprovalOutcome(
            ForceDeleteAction::make(),
            Operation::ForceDelete,
            'Request permanent deletion',
            'Permanent deletion sent for approval',
            static fn (Model $record): ?bool => $record->forceDelete(),
        );
    }

    public static function approvalAwareRestoreAction(): RestoreAction
    {
        return self::reportingApprovalOutcome(
            RestoreAction::make(),
            Operation::Restore,
            'Request restore',
            'Restore sent for approval',
            static fn (Model $record): ?bool => $record->restore(),
        );
    }

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

        $this->saveReportingApprovalOutcome($record);

        return $record;
    }

    /**
     * Save the record and remember whether the change was sent for approval. A record whose
     * deletion waits for approval refuses the save: the page says why and stops.
     */
    protected function saveReportingApprovalOutcome(Model $record): void
    {
        try {
            $this->sentForApproval = ! $record->save();
        } catch (PendingDeletionLock) {
            Notification::make()->danger()->title(__('app.messages.deletionAwaitingApproval'))->send();
            $this->halt();
        }

        if ($this->sentForApproval) {
            $record->refresh();
        }
    }

    #[Override]
    protected function getSavedNotification(): ?Notification
    {
        if (! $this->sentForApproval) {
            return parent::getSavedNotification();
        }

        return Notification::make()
            ->warning()
            ->title(__('app.messages.changeSentForApproval'))
            ->body(__('app.messages.changeSentForApprovalBody'));
    }

    /**
     * Label the action as a request when the operation would be captured, and report a
     * captured operation as sent for approval rather than as a failure.
     *
     * @template TAction of Action
     *
     * @param  TAction  $action
     * @param  Closure(Model): ?bool  $run
     * @return TAction
     */
    private static function reportingApprovalOutcome(Action $action, Operation $operation, string $requestLabel, string $sentTitle, Closure $run): Action
    {
        $label = $action->getLabel();

        return $action
            ->label(static fn (Model $record): string|Htmlable|null => method_exists($record, 'wouldRequireApproval') && $record->wouldRequireApproval($operation) ? $requestLabel : $label)
            ->action(static function (Action $action, Model $record) use ($run, $sentTitle): void {
                if ($run($record)) {
                    $action->success();

                    return;
                }

                if (method_exists($record, 'pendingModification') && $record->pendingModification() !== null) {
                    Notification::make()->warning()->title($sentTitle)->send();
                    $action->cancel();
                }

                $action->failure();
            });
    }
}
