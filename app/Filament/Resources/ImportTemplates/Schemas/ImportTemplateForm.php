<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportTemplates\Schemas;

use App\Enums\ImportFileFormat;
use App\Enums\ImportTargetField;
use App\Models\Branch;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

final class ImportTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('import_templates.sections.general'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('import_templates.fields.name'))
                            ->required()
                            ->maxLength(120),

                        Select::make('company_id')
                            ->label(__('import_templates.fields.company_id'))
                            ->relationship('company', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->live()
                            ->afterStateUpdated(fn (Set $set): mixed => $set('branch_id', null)),

                        Select::make('branch_id')
                            ->label(__('import_templates.fields.branch_id'))
                            ->options(fn (Get $get): array => Branch::query()
                                ->when($get('company_id'), fn ($q, $id) => $q->where('company_id', $id))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText(__('import_templates.hints.branch_default')),

                        Select::make('accepted_format')
                            ->label(__('import_templates.fields.accepted_format'))
                            ->options(ImportFileFormat::class)
                            ->required()
                            ->native(false),

                        Toggle::make('is_active')
                            ->label(__('common.fields.is_active'))
                            ->default(true),
                    ]),

                Section::make(__('import_templates.sections.mappings'))
                    ->columns(1)
                    ->visibleOn('create')
                    ->schema([
                        Repeater::make('mappings')
                            ->label(__('import_templates.fields.mappings'))
                            ->schema([
                                TextInput::make('source_column')
                                    ->label(__('import_templates.fields.source_column'))
                                    ->maxLength(120)
                                    ->nullable(),
                                Select::make('target_field')
                                    ->label(__('import_templates.fields.target_field'))
                                    ->options(ImportTargetField::class)
                                    ->required()
                                    ->native(false),
                                TextInput::make('default_value')
                                    ->label(__('import_templates.fields.default_value'))
                                    ->maxLength(255)
                                    ->nullable(),
                            ])
                            ->minItems(1)
                            ->columnSpanFull()
                            ->addActionLabel(__('import_templates.actions.add_mapping')),
                    ]),
            ]);
    }
}
