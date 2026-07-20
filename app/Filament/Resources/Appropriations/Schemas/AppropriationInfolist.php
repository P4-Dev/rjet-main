<?php

declare(strict_types=1);

namespace App\Filament\Resources\Appropriations\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class AppropriationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('appropriations.sections.appropriation_info'))
                    ->schema([
                        TextEntry::make('company.name')
                            ->label(__('appropriations.fields.company')),

                        TextEntry::make('code')
                            ->label(__('appropriations.fields.code')),

                        TextEntry::make('name')
                            ->label(__('appropriations.fields.name')),

                        TextEntry::make('description')
                            ->label(__('appropriations.fields.description'))
                            ->placeholder('—')
                            ->columnSpanFull(),

                        TextEntry::make('sort_order')
                            ->label(__('appropriations.fields.sort_order')),

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
