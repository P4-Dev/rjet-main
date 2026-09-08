<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers\Schemas;

use App\Enums\AccountType;
use App\Enums\DepositType;
use App\Enums\PaymentMethod;
use App\Enums\PersonType;
use App\Enums\PixKeyType;
use App\Models\Bank;
use App\Rules\ValidCnpj;
use App\Rules\ValidCpf;
use App\Rules\ValidCpfOrCnpj;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('suppliers.sections.identity'))
                    ->schema([
                        Select::make('person_type')
                            ->label(__('suppliers.fields.person_type'))
                            ->options(PersonType::class)
                            ->required()
                            ->live()
                            ->native(false),

                        TextInput::make('document')
                            ->label(__('suppliers.fields.document'))
                            ->required()
                            ->maxLength(20)
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null
                                ? preg_replace('/\D/', '', $state)
                                : null)
                            ->rule(function (Get $get): ValidCpf|ValidCnpj {
                                $personType = $get('person_type');
                                $value = $personType instanceof PersonType
                                    ? $personType->value
                                    : (string) $personType;

                                return $value === PersonType::Pf->value
                                    ? new ValidCpf
                                    : new ValidCnpj;
                            })
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn ($rule) => $rule->whereNull('deleted_at'),
                            ),

                        TextInput::make('name')
                            ->label(__('suppliers.fields.name'))
                            ->required()
                            ->maxLength(150),

                        TextInput::make('legal_name')
                            ->label(__('suppliers.fields.legal_name'))
                            ->maxLength(200)
                            ->visible(function (Get $get): bool {
                                $personType = $get('person_type');
                                $value = $personType instanceof PersonType
                                    ? $personType->value
                                    : (string) $personType;

                                return $value === PersonType::Pj->value;
                            }),
                    ])
                    ->columns(2),

                Section::make(__('suppliers.sections.contact_quick'))
                    ->schema([
                        TextInput::make('email')
                            ->label(__('suppliers.fields.email'))
                            ->email()
                            ->maxLength(255),

                        TextInput::make('phone')
                            ->label(__('suppliers.fields.phone'))
                            ->maxLength(20),
                    ])
                    ->columns(2),

                Section::make(__('suppliers.sections.payment'))
                    ->schema([
                        Select::make('default_payment_method')
                            ->label(__('suppliers.fields.default_payment_method'))
                            ->options(PaymentMethod::class)
                            ->required()
                            ->columnSpanFull()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (PaymentMethod|string|null $state, Set $set): void {
                                $method = $state instanceof PaymentMethod ? $state->value : $state;

                                if ($method !== PaymentMethod::Deposit->value) {
                                    $set('bankDetails.deposit_type', null);
                                    $set('bankDetails.pix_key_type', null);
                                    $set('bankDetails.pix_key', null);
                                    $set('bankDetails.holder_document', null);
                                    $set('bankDetails.holder_name', null);
                                    $set('bankDetails.bank_id', null);
                                    $set('bankDetails.agency', null);
                                    $set('bankDetails.agency_digit', null);
                                    $set('bankDetails.account_number', null);
                                    $set('bankDetails.account_digit', null);
                                    $set('bankDetails.account_type', null);
                                }
                            }),

                        Section::make(__('payment_request_bank_details.label'))
                            ->description(__('suppliers.hints.bank_details'))
                            ->columnSpanFull()
                            ->relationship('bankDetails')
                            ->schema([
                                Select::make('deposit_type')
                                    ->label(__('payment_request_bank_details.fields.deposit_type'))
                                    ->options(DepositType::class)
                                    ->native(false)
                                    ->live()
                                    ->visible(fn (Get $get): bool => self::isDeposit($get))
                                    ->required(fn (Get $get): bool => self::isDeposit($get))
                                    ->afterStateUpdated(function (DepositType|string|null $state, Get $get, Set $set): void {
                                        $type = $state instanceof DepositType ? $state->value : $state;

                                        if ($type !== DepositType::Pix->value) {
                                            $set('pix_key_type', null);
                                            $set('pix_key', null);
                                        }

                                        if ($type !== DepositType::Transfer->value) {
                                            $set('holder_document', null);
                                            $set('holder_name', null);
                                            $set('bank_id', null);
                                            $set('agency', null);
                                            $set('agency_digit', null);
                                            $set('account_number', null);
                                            $set('account_digit', null);
                                            $set('account_type', null);

                                            return;
                                        }

                                        if (blank($get('holder_name'))) {
                                            $set('holder_name', filled($get('../legal_name'))
                                                ? $get('../legal_name')
                                                : $get('../name'));
                                        }

                                        if (blank($get('holder_document'))) {
                                            $set('holder_document', $get('../document'));
                                        }
                                    }),

                                Select::make('pix_key_type')
                                    ->label(__('payment_request_bank_details.fields.pix_key_type'))
                                    ->options(PixKeyType::class)
                                    ->native(false)
                                    ->live()
                                    ->visible(fn (Get $get): bool => self::isPix($get))
                                    ->required(fn (Get $get): bool => self::isPix($get))
                                    ->afterStateUpdated(fn (Set $set): mixed => $set('pix_key', null)),

                                TextInput::make('pix_key')
                                    ->label(__('payment_request_bank_details.fields.pix_key'))
                                    ->maxLength(100)
                                    ->visible(fn (Get $get): bool => self::isPix($get))
                                    ->required(fn (Get $get): bool => self::isPix($get))
                                    ->placeholder(fn (Get $get): ?string => $get->enum('pix_key_type', PixKeyType::class, isNullable: true)?->inputPlaceholder())
                                    ->rules(fn (Get $get): array => $get->enum('pix_key_type', PixKeyType::class, isNullable: true)?->validationRules() ?? [])
                                    ->dehydrateStateUsing(function (?string $state, Get $get): ?string {
                                        $type = $get->enum('pix_key_type', PixKeyType::class, isNullable: true);

                                        return in_array($type, [PixKeyType::Cpf, PixKeyType::Phone], true)
                                            ? (self::onlyDigits($state) ?: null)
                                            : $state;
                                    }),

                                TextInput::make('holder_document')
                                    ->label(__('payment_request_bank_details.fields.holder_document'))
                                    ->maxLength(20)
                                    ->visible(fn (Get $get): bool => self::isTransfer($get))
                                    ->required(fn (Get $get): bool => self::isTransfer($get))
                                    ->rule(new ValidCpfOrCnpj)
                                    ->dehydrateStateUsing(fn (?string $state): ?string => self::onlyDigits($state) ?: null)
                                    ->helperText(__('payment_requests.hints.holder_document')),

                                TextInput::make('holder_name')
                                    ->label(__('payment_request_bank_details.fields.holder_name'))
                                    ->maxLength(150)
                                    ->visible(fn (Get $get): bool => self::isTransfer($get)),

                                Select::make('bank_id')
                                    ->label(__('payment_request_bank_details.fields.bank_id'))
                                    ->relationship(
                                        name: 'bank',
                                        titleAttribute: 'name',
                                        modifyQueryUsing: fn (Builder $query): Builder => $query->active()->orderBy('name'),
                                    )
                                    ->getOptionLabelFromRecordUsing(
                                        fn (Bank $record): string => "{$record->code} - {$record->name}"
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->visible(fn (Get $get): bool => self::isTransfer($get))
                                    ->required(fn (Get $get): bool => self::isTransfer($get)),

                                TextInput::make('agency')
                                    ->label(__('payment_request_bank_details.fields.agency'))
                                    ->maxLength(10)
                                    ->visible(fn (Get $get): bool => self::isTransfer($get))
                                    ->required(fn (Get $get): bool => self::isTransfer($get))
                                    ->dehydrateStateUsing(fn (?string $state): ?string => self::onlyDigits($state) ?: null),

                                TextInput::make('agency_digit')
                                    ->label(__('payment_request_bank_details.fields.agency_digit'))
                                    ->maxLength(2)
                                    ->visible(fn (Get $get): bool => self::isTransfer($get)),

                                TextInput::make('account_number')
                                    ->label(__('payment_request_bank_details.fields.account_number'))
                                    ->maxLength(20)
                                    ->visible(fn (Get $get): bool => self::isTransfer($get))
                                    ->required(fn (Get $get): bool => self::isTransfer($get))
                                    ->dehydrateStateUsing(fn (?string $state): ?string => self::onlyDigits($state) ?: null),

                                TextInput::make('account_digit')
                                    ->label(__('payment_request_bank_details.fields.account_digit'))
                                    ->maxLength(2)
                                    ->visible(fn (Get $get): bool => self::isTransfer($get))
                                    ->required(fn (Get $get): bool => self::isTransfer($get)),

                                Select::make('account_type')
                                    ->label(__('payment_request_bank_details.fields.account_type'))
                                    ->options(AccountType::class)
                                    ->native(false)
                                    ->visible(fn (Get $get): bool => self::isTransfer($get))
                                    ->required(fn (Get $get): bool => self::isTransfer($get)),
                            ]),

                        Textarea::make('notes')
                            ->label(__('suppliers.fields.notes'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('suppliers.sections.flags'))
                    ->schema([
                        Toggle::make('is_active')
                            ->label(__('common.fields.is_active'))
                            ->default(true),
                    ]),
            ]);
    }

    private static function paymentMethod(Get $get): ?PaymentMethod
    {
        return $get->enum('../default_payment_method', PaymentMethod::class, isNullable: true)
            ?? $get->enum('default_payment_method', PaymentMethod::class, isNullable: true);
    }

    private static function depositType(Get $get): ?DepositType
    {
        return $get->enum('deposit_type', DepositType::class, isNullable: true);
    }

    private static function isDeposit(Get $get): bool
    {
        return self::paymentMethod($get) === PaymentMethod::Deposit;
    }

    private static function isPix(Get $get): bool
    {
        return self::isDeposit($get) && self::depositType($get) === DepositType::Pix;
    }

    private static function isTransfer(Get $get): bool
    {
        return self::isDeposit($get) && self::depositType($get) === DepositType::Transfer;
    }

    private static function onlyDigits(?string $state): ?string
    {
        return $state === null ? null : preg_replace('/\D/', '', $state);
    }
}
