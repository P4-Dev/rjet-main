<?php

declare(strict_types=1);

namespace App\Filament\Resources\Companies\Schemas;

use App\Rules\ValidCnpj;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('companies.sections.company_info'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('companies.fields.name'))
                            ->required()
                            ->maxLength(150),

                        TextInput::make('legal_name')
                            ->label(__('companies.fields.legal_name'))
                            ->maxLength(200),

                        TextInput::make('document')
                            ->label(__('companies.fields.document'))
                            ->required()
                            ->maxLength(20)
                            ->rule(new ValidCnpj)
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->whereNull('deleted_at')),

                        Toggle::make('is_active')
                            ->label(__('common.fields.is_active'))
                            ->default(true),

                        Toggle::make('is_appropriation_required')
                            ->label(__('companies.fields.is_appropriation_required'))
                            ->helperText(__('companies.hints.is_appropriation_required'))
                            ->default(false)
                            ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false),
                    ])
                    ->columns(2),
            ]);
    }
}