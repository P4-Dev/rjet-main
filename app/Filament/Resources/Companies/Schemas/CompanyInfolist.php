<?php

declare(strict_types=1);

namespace App\Filament\Resources\Companies\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CompanyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('companies.sections.company_info'))
                    ->schema([
                        TextEntry::make('name')
                            ->label(__('companies.fields.name')),

                        TextEntry::make('legal_name')
                            ->label(__('companies.fields.legal_name'))
                            ->placeholder('—'),

                        TextEntry::make('document')
                            ->label(__('companies.fields.document')),

                        IconEntry::make('is_active')
                            ->label(__('common.fields.is_active'))
                            ->boolean(),

                        IconEntry::make('is_appropriation_required')
                            ->label(__('companies.fields.is_appropriation_required'))
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
