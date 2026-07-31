<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\Actions;

use App\Enums\PaymentRequestStatus;
use App\Exceptions\BusinessException;
use App\Models\PaymentRequest;
use App\Services\PaymentRequestService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class TransitionStatusAction
{
    public static function make(): Action
    {
        return Action::make('transitionStatus')
            ->label(__('payment_requests.actions.transition_status'))
            ->icon(Heroicon::OutlinedArrowRightCircle)
            ->color('info')
            ->visible(function (?PaymentRequest $record): bool {
                if ($record === null) {
                    return false;
                }

                return $record->status->allowedTransitions() !== []
                    && (Filament::auth()->user()?->can('transitionStatus', $record) ?? false);
            })
            ->modalHeading(__('payment_requests.actions.transition_status'))
            ->modalDescription(__('payment_requests.messages.confirm_transition'))
            ->modalSubmitActionLabel(__('common.actions.confirm'))
            ->schema([
                Select::make('to_status')
                    ->label(__('payment_requests.fields.next_status'))
                    ->options(fn (PaymentRequest $record): array => collect($record->status->allowedTransitions())
                        ->mapWithKeys(fn (PaymentRequestStatus $status): array => [$status->value => $status->getLabel()])
                        ->all())
                    ->required()
                    ->native(false),
                Textarea::make('notes')
                    ->label(__('common.fields.notes'))
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(function (PaymentRequest $record, array $data, Action $action): void {
                try {
                    app(PaymentRequestService::class)->transitionStatus(
                        $record,
                        PaymentRequestStatus::from($data['to_status']),
                        Filament::auth()->user(),
                        $data['notes'] ?? null,
                    );
                } catch (BusinessException $exception) {
                    Notification::make()
                        ->title($exception->getUserMessage())
                        ->danger()
                        ->send();

                    $action->halt();
                }

                Notification::make()
                    ->title(__('payment_requests.messages.status_changed'))
                    ->success()
                    ->send();
            });
    }
}
