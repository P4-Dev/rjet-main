<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportTemplates\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ImportTemplateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('import_templates.sections.general'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')
                            ->label(__('import_templates.fields.name')),
                        TextEntry::make('company.name')
                            ->label(__('import_templates.fields.company_id'))
                            ->placeholder('—'),
                        TextEntry::make('branch.name')
                            ->label(__('import_templates.fields.branch_id'))
                            ->placeholder('—'),
                        TextEntry::make('accepted_format')
                            ->label(__('import_templates.fields.accepted_format'))
                            ->badge(),
                        IconEntry::make('is_active')
                            ->label(__('common.fields.is_active'))
                            ->boolean(),
                        TextEntry::make('currentVersion.version')
                            ->label(__('import_templates.fields.current_version')),
                        TextEntry::make('created_at')
                            ->label(__('common.fields.created_at'))
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('updated_at')
                            ->label(__('common.fields.updated_at'))
                            ->dateTime('d/m/Y H:i'),
                    ]),
            ]);
    }
}
