<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSettlements\Actions;

use App\Actions\Settlement\CreatePaymentSettlementAction;
use App\DTOs\PaymentSettlementData;
use App\Exceptions\BusinessException;
use App\Filament\Resources\PaymentSettlements\PaymentSettlementResource;
use App\Filament\Resources\PaymentSettlements\Schemas\PaymentSettlementForm;
use App\Models\PaymentRequest;
use App\Models\PaymentSettlement;
use Carbon\CarbonImmutable;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

final class CreatePaymentSettlementBulkAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('createSettlement')
            ->label(__('payment_settlements.actions.settle_selected'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('primary')
            ->visible(fn (): bool => Filament::auth()->user()?->can('create', PaymentSettlement::class) ?? false)
            ->modalHeading(__('payment_settlements.actions.settle_selected'))
            ->modalDescription(fn (Collection $records): string => PaymentSettlementForm::singleBranchId($records) === null
                ? __('payment_settlements.errors.mixed_branches')
                : __('payment_settlements.messages.selection_summary', [
                    'count' => $records->count(),
                    'total' => Number::currency((float) $records->reduce(
                        fn (string $carry, PaymentRequest $request): string => bcadd($carry, (string) $request->net_amount, 2),
                        '0.00',
                    ), 'BRL', 'pt_BR'),
                ]))
            ->schema(PaymentSettlementForm::components())
            ->action(function (Collection $records, array $data, BulkAction $action): void {
                $user = Filament::auth()->user();

                abort_unless($user?->can('create', PaymentSettlement::class) ?? false, 403);

                $branchId = PaymentSettlementForm::singleBranchId($records);

                if ($branchId === null) {
                    Notification::make()
                        ->title(__('payment_settlements.errors.mixed_branches'))
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                try {
                    $settlement = app(CreatePaymentSettlementAction::class)(
                        new PaymentSettlementData(
                            branchId: $branchId,
                            branchBankAccountId: (string) $data['branch_bank_account_id'],
                            settlementDate: CarbonImmutable::parse($data['settlement_date'], PaymentSettlement::TIMEZONE),
                            paymentRequestIds: array_map('strval', $records->modelKeys()),
                            notes: $data['notes'] ?? null,
                        ),
                        $user,
                    );
                } catch (BusinessException $exception) {
                    Notification::make()
                        ->title($exception->getUserMessage())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__('payment_settlements.messages.created'))
                    ->success()
                    ->send();

                $action->redirect(PaymentSettlementResource::getUrl('view', ['record' => $settlement]));
            })
            ->deselectRecordsAfterCompletion();
    }
}
