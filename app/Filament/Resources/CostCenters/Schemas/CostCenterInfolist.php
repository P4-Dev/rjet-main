<?php

declare(strict_types=1);

namespace App\Filament\Resources\CostCenters\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CostCenterInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('cost_centers.sections.cost_center_info'))
                    ->schema([
                        TextEntry::make('branch.company.name')
                            ->label(__('cost_centers.fields.company')),

                        TextEntry::make('branch.name')
                            ->label(__('cost_centers.fields.branch')),

                        TextEntry::make('code')
                            ->label(__('cost_centers.fields.code')),

                        TextEntry::make('name')
                            ->label(__('cost_centers.fields.name')),

                        TextEntry::make('description')
                            ->label(__('cost_centers.fields.description'))
                            ->placeholder('—')
                            ->columnSpanFull(),

                        TextEntry::make('sort_order')
                            ->label(__('cost_centers.fields.sort_order')),

                        IconEntry::make('is_active')
                            ->label(__('common.fields.is_active'))
                            ->boolean(),
                    ])
                    ->columns(2),

                Section::make(__('common.sections.audit'))
                    ->schema([
                        TextEntry::make('created_at')
                            ->label(__('common.fields.created_at'))
                            ->dateTime('d/m/Y H:i'),

                        TextEntry::make('updated_at')
                            ->label(__('common.fields.updated_at'))
                            ->dateTime('d/m/Y H:i'),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }
}
