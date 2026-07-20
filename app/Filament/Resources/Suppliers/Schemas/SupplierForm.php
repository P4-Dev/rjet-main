<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers\Schemas;

use App\Enums\PaymentMethod;
use App\Enums\PersonType;
use App\Rules\ValidCnpj;
use App\Rules\ValidCpf;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

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
                            ->native(false),

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
}
