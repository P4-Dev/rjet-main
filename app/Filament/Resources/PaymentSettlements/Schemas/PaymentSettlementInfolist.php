<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSettlements\Schemas;

use App\Enums\CnabFileStatus;
use App\Enums\PaymentSettlementStatus;
use App\Filament\Resources\CnabConfigs\Schemas\CnabConfigForm;
use App\Models\PaymentSettlement;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class PaymentSettlementInfolist
{
    public const POLLING_INTERVAL = '5s';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('payment_settlements.sections.summary'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('payment_settlements.fields.status'))
                            ->badge(),
                        TextEntry::make('branch.name')
                            ->label(__('payment_settlements.fields.branch')),
                        TextEntry::make('account')
                            ->label(__('payment_settlements.fields.branch_bank_account'))
                            ->state(fn (PaymentSettlement $record): ?string => $record->branchBankAccount !== null
                                ? CnabConfigForm::accountLabel($record->branchBankAccount)
                                : null)
                            ->placeholder('—'),
                        TextEntry::make('settlement_date')
                            ->label(__('payment_settlements.fields.settlement_date'))
                            ->date('d/m/Y'),
                        TextEntry::make('items_count')
                            ->label(__('payment_settlements.fields.items_count')),
                        TextEntry::make('total_amount')
                            ->label(__('payment_settlements.fields.total_amount'))
                            ->money('BRL'),
                        TextEntry::make('notes')
                            ->label(__('common.fields.notes'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
                Section::make(__('payment_settlements.sections.cnab'))
                    ->key('cnab')
                    ->poll(fn (PaymentSettlement $record): ?string => $record->currentCnabFile?->status->isInProgress() ? self::POLLING_INTERVAL : null)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('currentCnabFile.status')
                            ->label(__('payment_settlements.fields.cnab_status'))
                            ->badge()
                            ->placeholder(__('payment_settlements.messages.no_cnab')),
                        TextEntry::make('currentCnabFile.file_sequence')
                            ->label(__('cnab_files.fields.file_sequence'))
                            ->placeholder('—'),
                        TextEntry::make('currentCnabFile.filename')
                            ->label(__('cnab_files.fields.filename'))
                            ->placeholder('—'),
                        TextEntry::make('currentCnabFile.generated_at')
                            ->label(__('cnab_files.fields.generated_at'))
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('latestCnabFile.failure_reason')
                            ->label(__('cnab_files.fields.failure_reason'))
                            ->visible(fn (PaymentSettlement $record): bool => $record->latestCnabFile?->status === CnabFileStatus::Failed)
                            ->color('danger')
                            ->columnSpanFull(),
                        TextEntry::make('cnab_hint')
                            ->hiddenLabel()
                            ->state(__('payment_settlements.hints.cnab_unavailable'))
                            ->color('warning')
                            ->visible(fn (PaymentSettlement $record): bool => $record->isDraft() && $record->cnabConfig() === null)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('common.sections.audit'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('creator.name')
                            ->label(__('common.fields.created_by'))
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label(__('common.fields.created_at'))
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('settler.name')
                            ->label(__('payment_settlements.fields.settled_by'))
                            ->placeholder('—'),
                        TextEntry::make('settled_at')
                            ->label(__('payment_settlements.fields.settled_at'))
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('canceller.name')
                            ->label(__('payment_settlements.fields.cancelled_by'))
                            ->placeholder('—'),
                        TextEntry::make('cancelled_at')
                            ->label(__('payment_settlements.fields.cancelled_at'))
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('cancellation_reason')
                            ->label(__('payment_settlements.fields.cancellation_reason'))
                            ->visible(fn (PaymentSettlement $record): bool => $record->status === PaymentSettlementStatus::Cancelled)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
