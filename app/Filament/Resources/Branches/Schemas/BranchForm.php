<?php

declare(strict_types=1);

namespace App\Filament\Resources\Branches\Schemas;

use App\Rules\ValidCnpj;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class BranchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('branches.sections.branch_info'))
                    ->schema([
                        Select::make('company_id')
                            ->label(__('branches.fields.company_id'))
                            ->relationship('company', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),

                        TextInput::make('name')
                            ->label(__('branches.fields.name'))
                            ->required()
                            ->maxLength(150),

                        TextInput::make('legal_name')
                            ->label(__('branches.fields.legal_name'))
                            ->required()
                            ->maxLength(200),

                        TextInput::make('document')
                            ->label(__('branches.fields.document'))
                            ->required()
                            ->maxLength(20)
                            ->rule(new ValidCnpj)
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->whereNull('deleted_at')),

                        Toggle::make('is_active')
                            ->label(__('common.fields.is_active'))
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
