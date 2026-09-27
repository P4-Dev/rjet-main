<?php

declare(strict_types=1);

namespace App\Filament\Resources\CnabConfigs\Schemas;

use App\Models\CnabConfig;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CnabConfigInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('cnab_configs.sections.account'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('branchBankAccount.branch.name')
                            ->label(__('cnab_configs.fields.branch')),
                        TextEntry::make('account')
                            ->label(__('cnab_configs.fields.branch_bank_account'))
                            ->state(fn (CnabConfig $record): ?string => $record->branchBankAccount !== null
                                ? CnabConfigForm::accountLabel($record->branchBankAccount)
                                : null)
                            ->placeholder('—'),
                        TextEntry::make('layout')
                            ->label(__('cnab_configs.fields.layout'))
                            ->badge(),
                    ]),
                Section::make(__('cnab_configs.sections.parameters'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('company_name')
                            ->label(__('cnab_configs.fields.company_name'))
                            ->placeholder('—'),
                        TextEntry::make('agreement_code')
                            ->label(__('cnab_configs.fields.agreement_code'))
                            ->placeholder('—'),
                        TextEntry::make('wallet_code')
                            ->label(__('cnab_configs.fields.wallet_code'))
                            ->placeholder('—'),
                        TextEntry::make('payment_type_code')
                            ->label(__('cnab_configs.fields.payment_type_code'))
                            ->placeholder('—'),
                        TextEntry::make('last_file_sequence')
                            ->label(__('cnab_configs.fields.last_file_sequence')),
                        IconEntry::make('is_active')
                            ->label(__('cnab_configs.fields.is_active'))
                            ->boolean(),
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
                        TextEntry::make('updater.name')
                            ->label(__('common.fields.updated_by'))
                            ->placeholder('—'),
                        TextEntry::make('updated_at')
                            ->label(__('common.fields.updated_at'))
                            ->dateTime('d/m/Y H:i'),
                    ]),
            ]);
    }
}
