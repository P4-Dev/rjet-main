<?php

declare(strict_types=1);

namespace App\Filament\Resources\CnabConfigs\Schemas;

use App\Enums\CnabLayout;
use App\Models\Branch;
use App\Models\BranchBankAccount;
use App\Models\CnabConfig;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

final class CnabConfigForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('cnab_configs.sections.account'))
                    ->columns(2)
                    ->schema([
                        Select::make('branch_id')
                            ->label(__('cnab_configs.fields.branch'))
                            ->options(fn (): array => Branch::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->required()
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(fn (Set $set): mixed => $set('branch_bank_account_id', null))
                            ->afterStateHydrated(fn (Select $component, ?CnabConfig $record): mixed => $component->state($record?->branchBankAccount?->branch_id))
                            ->dehydrated(false)
                            ->disabled(fn (?CnabConfig $record): bool => $record?->hasIssuedFiles() ?? false),
                        Select::make('branch_bank_account_id')
                            ->label(__('cnab_configs.fields.branch_bank_account'))
                            ->options(fn (Get $get): array => self::accountOptions($get('branch_id')))
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->disabled(fn (Get $get, ?CnabConfig $record): bool => blank($get('branch_id')) || ($record?->hasIssuedFiles() ?? false))
                            ->dehydrated(),
                        Select::make('layout')
                            ->label(__('cnab_configs.fields.layout'))
                            ->options(CnabLayout::class)
                            ->default(CnabLayout::Itau240)
                            ->required()
                            ->rule(Rule::enum(CnabLayout::class))
                            ->native(false)
                            ->disabled(fn (?CnabConfig $record): bool => $record?->hasIssuedFiles() ?? false)
                            ->dehydrated()
                            ->helperText(__('cnab_configs.help.layout')),
                    ]),
                Section::make(__('cnab_configs.sections.parameters'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('company_name')
                            ->label(__('cnab_configs.fields.company_name'))
                            ->maxLength(30)
                            ->helperText(__('cnab_configs.help.company_name_fallback')),
                        TextInput::make('agreement_code')
                            ->label(__('cnab_configs.fields.agreement_code'))
                            ->maxLength(20)
                            ->regex('/^[A-Za-z0-9]+$/'),
                        TextInput::make('wallet_code')
                            ->label(__('cnab_configs.fields.wallet_code'))
                            ->maxLength(10),
                        TextInput::make('payment_type_code')
                            ->label(__('cnab_configs.fields.payment_type_code'))
                            ->default('20')
                            ->required()
                            ->length(2)
                            ->regex('/^\d{2}$/')
                            ->helperText(__('cnab_configs.help.payment_type_code')),
                        TextInput::make('last_file_sequence')
                            ->label(__('cnab_configs.fields.last_file_sequence'))
                            ->numeric()
                            ->integer()
                            ->default(0)
                            ->required()
                            ->minValue(0)
                            ->maxValue(CnabConfig::MAX_FILE_SEQUENCE)
                            ->disabled(fn (?CnabConfig $record): bool => $record?->hasIssuedFiles() ?? false)
                            ->dehydrated()
                            ->helperText(__('cnab_configs.help.last_file_sequence')),
                        Toggle::make('is_active')
                            ->label(__('cnab_configs.fields.is_active'))
                            ->default(true)
                            ->helperText(__('cnab_configs.help.is_active')),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function accountOptions(mixed $branchId): array
    {
        if (blank($branchId)) {
            return [];
        }

        return BranchBankAccount::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->whereNotNull('bank_id')
            ->orderBy('bank_code')
            ->get()
            ->mapWithKeys(fn (BranchBankAccount $account): array => [
                (string) $account->getKey() => self::accountLabel($account),
            ])
            ->all();
    }

    public static function accountLabel(BranchBankAccount $account): string
    {
        return __('cnab_configs.formats.account', [
            'bank' => $account->bank_code,
            'agency' => $account->agency,
            'account' => $account->account_number,
            'digit' => $account->account_digit,
        ]);
    }
}
