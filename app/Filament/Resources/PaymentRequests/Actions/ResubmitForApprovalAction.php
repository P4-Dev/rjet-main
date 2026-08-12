<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\Actions;

use App\Exceptions\BusinessException;
use App\Models\PaymentRequest;
use App\Services\ApprovalService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class ResubmitForApprovalAction
{
    public static function make(): Action
    {
        return Action::make('resubmitForApproval')
            ->label(__('payment_requests.actions.resubmit'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->visible(function (?PaymentRequest $record): bool {
                if ($record === null) {
                    return false;
                }

                $user = Filament::auth()->user();
                if ($user === null || ! $user->can('resubmitForApproval', $record)) {
                    return false;
                }

                if ($record->isAwaitingApproval() || $record->hasApprovedForLaunch()) {
                    return false;
                }

                return $record->isReturnedToRequester()
                    || ($record->latestApproval()?->status?->value === 'approved' && ! $record->hasApprovedForLaunch())
                    || ($record->latestApproval() === null);
            })
            ->action(function (PaymentRequest $record, Action $action): void {
                $user = Filament::auth()->user();
                abort_unless($user !== null && $user->can('resubmitForApproval', $record), 403);

                try {
                    app(ApprovalService::class)->resubmit($record, $user);
                } catch (BusinessException $exception) {
                    Notification::make()
                        ->title($exception->getUserMessage())
                        ->warning()
                        ->send();

                    $action->halt();
                }

                Notification::make()
                    ->title(__('payment_requests.messages.resubmitted'))
                    ->success()
                    ->send();
            });
    }
}
