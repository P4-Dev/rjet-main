<?php

declare(strict_types=1);

namespace App\Filament\Resources\Appropriations\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class AppropriationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('appropriations.sections.appropriation_info'))
                    ->schema([
                        Select::make('company_id')
                            ->label(__('appropriations.fields.company_id'))
                            ->relationship('company', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),

                        TextInput::make('code')
                            ->label(__('appropriations.fields.code'))
                            ->required()
                            ->maxLength(30)
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn ($rule, $get) => $rule
                                    ->where('company_id', $get('company_id'))
                                    ->whereNull('deleted_at'),
                            ),

                        TextInput::make('name')
                            ->label(__('appropriations.fields.name'))
                            ->required()
                            ->maxLength(150),

                        Textarea::make('description')
                            ->label(__('appropriations.fields.description'))
                            ->maxLength(500)
                            ->columnSpanFull(),

                        TextInput::make('sort_order')
                            ->label(__('appropriations.fields.sort_order'))
                            ->numeric()
                            ->default(0),

                        Toggle::make('is_active')
                            ->label(__('common.fields.is_active'))
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
