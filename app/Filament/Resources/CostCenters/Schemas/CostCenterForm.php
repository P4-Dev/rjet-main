<?php

declare(strict_types=1);

namespace App\Filament\Resources\CostCenters\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CostCenterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('cost_centers.sections.cost_center_info'))
                    ->schema([
                        Select::make('branch_id')
                            ->label(__('cost_centers.fields.branch_id'))
                            ->relationship(
                                'branch',
                                'name',
                                modifyQueryUsing: fn ($query) => $query->with('company'),
                            )
                            ->getOptionLabelFromRecordUsing(
                                fn ($record): string => "{$record->company->name} / {$record->name}"
                            )
                            ->searchable()
                            ->preload()
                            ->required(),

                        TextInput::make('code')
                            ->label(__('cost_centers.fields.code'))
                            ->required()
                            ->maxLength(30)
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn ($rule, $get) => $rule
                                    ->where('branch_id', $get('branch_id'))
                                    ->whereNull('deleted_at'),
                            ),

                        TextInput::make('name')
                            ->label(__('cost_centers.fields.name'))
                            ->required()
                            ->maxLength(150),

                        Textarea::make('description')
                            ->label(__('cost_centers.fields.description'))
                            ->maxLength(500)
                            ->columnSpanFull(),

                        TextInput::make('sort_order')
                            ->label(__('cost_centers.fields.sort_order'))
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
