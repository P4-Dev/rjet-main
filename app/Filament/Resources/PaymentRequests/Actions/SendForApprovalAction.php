<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\Actions;

use App\Enums\PaymentRequestStatus;
use App\Exceptions\BusinessException;
use App\Models\PaymentRequest;
use App\Services\ApprovalService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class SendForApprovalAction
{
    public static function make(): Action
    {
        return Action::make('sendForApproval')
            ->label(__('payment_requests.actions.send_for_approval'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->requiresConfirmation()
            ->visible(function (?PaymentRequest $record): bool {
                if ($record === null) {
                    return false;
                }

                $user = Filament::auth()->user();

                return $record->status === PaymentRequestStatus::Requested
                    && ! $record->isAwaitingApproval()
                    && ! $record->hasApprovedForLaunch()
                    && ($user?->can('update', $record) ?? false);
            })
            ->action(function (PaymentRequest $record, Action $action): void {
                $user = Filament::auth()->user();
                abort_unless($user !== null && $user->can('update', $record), 403);

                try {
                    app(ApprovalService::class)->route($record, $user);
                } catch (BusinessException $exception) {
                    Notification::make()
                        ->title($exception->getUserMessage())
                        ->warning()
                        ->send();

                    $action->halt();
                }

                Notification::make()
                    ->title(__('payment_requests.messages.sent_for_approval'))
                    ->success()
                    ->send();
            });
    }
}
