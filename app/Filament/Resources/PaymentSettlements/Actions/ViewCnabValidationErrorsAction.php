<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSettlements\Actions;

use App\Enums\CnabFileStatus;
use App\Models\CnabFile;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

final class ViewCnabValidationErrorsAction
{
    public static function make(): Action
    {
        return Action::make('viewCnabErrors')
            ->label(__('cnab_files.actions.view_errors'))
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('gray')
            ->visible(fn (CnabFile $record): bool => $record->status === CnabFileStatus::Failed
                && $record->items()->where('is_valid', false)->exists()
                && (Filament::auth()->user()?->can('view', $record) ?? false))
            ->modalHeading(__('cnab_files.actions.view_errors'))
            ->modalContent(function (CnabFile $record): View {
                abort_unless(Filament::auth()->user()?->can('view', $record) ?? false, 403);

                return view('filament.cnab.file-errors', [
                    'items' => $record->items()
                        ->where('is_valid', false)
                        ->with('settlementItem.paymentRequest.supplier')
                        ->get(),
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('common.actions.cancel'));
    }
}
