<?php

declare(strict_types=1);

namespace App\Filament\Resources\Branches\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class BranchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('branches.sections.branch_info'))
                    ->schema([
                        TextEntry::make('company.name')
                            ->label(__('branches.fields.company_id')),

                        TextEntry::make('name')
                            ->label(__('branches.fields.name')),

                        TextEntry::make('legal_name')
                            ->label(__('branches.fields.legal_name')),

                        TextEntry::make('document')
                            ->label(__('branches.fields.document')),

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
