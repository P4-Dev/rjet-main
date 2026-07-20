<?php

declare(strict_types=1);

namespace App\Filament\Resources\Banks\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class BankInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('banks.sections.bank_info'))
                    ->schema([
                        TextEntry::make('code')
                            ->label(__('banks.fields.code')),

                        TextEntry::make('name')
                            ->label(__('banks.fields.name')),

                        TextEntry::make('ispb')
                            ->label(__('banks.fields.ispb'))
                            ->placeholder('—'),

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
