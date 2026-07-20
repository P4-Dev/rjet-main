<?php

declare(strict_types=1);

namespace App\Filament\Resources\Banks\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class BankForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('banks.sections.bank_info'))
                    ->schema([
                        TextInput::make('code')
                            ->label(__('banks.fields.code'))
                            ->required()
                            ->maxLength(3)
                            ->hint(__('banks.hints.code'))
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn ($rule) => $rule->whereNull('deleted_at'),
                            ),

                        TextInput::make('name')
                            ->label(__('banks.fields.name'))
                            ->required()
                            ->maxLength(150),

                        TextInput::make('ispb')
                            ->label(__('banks.fields.ispb'))
                            ->maxLength(8),

                        Toggle::make('is_active')
                            ->label(__('common.fields.is_active'))
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
