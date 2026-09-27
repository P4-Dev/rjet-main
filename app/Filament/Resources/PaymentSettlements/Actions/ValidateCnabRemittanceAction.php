<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSettlements\Actions;

use App\Actions\Cnab\ValidateCnabRemittanceAction as ValidateCnabRemittance;
use App\DTOs\CnabValidationReport;
use App\Exceptions\BusinessException;
use App\Models\PaymentSettlement;
use App\Models\PaymentSettlementItem;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

final class ValidateCnabRemittanceAction
{
    public static function make(): Action
    {
        return Action::make('validateCnab')
            ->label(__('payment_settlements.actions.validate_cnab'))
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('gray')
            ->visible(fn (?PaymentSettlement $record): bool => $record !== null
                && $record->isDraft()
                && ($record->cnabConfig()?->is_active ?? false)
                && (Filament::auth()->user()?->can('generateCnab', $record) ?? false))
            ->modalHeading(__('payment_settlements.actions.validate_cnab'))
            ->modalContent(function (PaymentSettlement $record): View {
                $user = Filament::auth()->user();

                abort_unless($user?->can('generateCnab', $record) ?? false, 403);

                try {
                    $report = app(ValidateCnabRemittance::class)($record, $user);

                    return view('filament.cnab.validation-report', [
                        'report' => $report,
                        'suppliers' => self::supplierNames($report),
                        'failure' => null,
                    ]);
                } catch (BusinessException $exception) {
                    return view('filament.cnab.validation-report', [
                        'report' => null,
                        'suppliers' => [],
                        'failure' => $exception->getUserMessage(),
                    ]);
                }
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('common.actions.cancel'));
    }

    /**
     * @return array<string, string>
     */
    public static function supplierNames(CnabValidationReport $report): array
    {
        if ($report->itemErrors === []) {
            return [];
        }

        return PaymentSettlementItem::query()
            ->with('paymentRequest.supplier')
            ->whereKey(array_keys($report->itemErrors))
            ->get()
            ->mapWithKeys(fn (PaymentSettlementItem $item): array => [
                (string) $item->getKey() => (string) ($item->paymentRequest?->supplier?->name ?? $item->payment_request_id),
            ])
            ->all();
    }
}
