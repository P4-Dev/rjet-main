<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SupplierInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('suppliers.sections.identity'))
                    ->schema([
                        TextEntry::make('person_type')
                            ->label(__('suppliers.fields.person_type'))
                            ->badge(),

                        TextEntry::make('document')
                            ->label(__('suppliers.fields.document')),

                        TextEntry::make('name')
                            ->label(__('suppliers.fields.name')),

                        TextEntry::make('legal_name')
                            ->label(__('suppliers.fields.legal_name'))
                            ->placeholder('—'),

                        TextEntry::make('default_payment_method')
                            ->label(__('suppliers.fields.default_payment_method'))
                            ->badge(),

                        TextEntry::make('email')
                            ->label(__('suppliers.fields.email'))
                            ->placeholder('—'),

                        TextEntry::make('phone')
                            ->label(__('suppliers.fields.phone'))
                            ->placeholder('—'),

                        IconEntry::make('is_active')
                            ->label(__('common.fields.is_active'))
                            ->boolean(),

                        TextEntry::make('notes')
                            ->label(__('suppliers.fields.notes'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('supplier_company_payment_methods.plural'))
                    ->schema([
                        RepeatableEntry::make('companyPaymentMethods')
                            ->schema([
                                TextEntry::make('company.name')
                                    ->label(__('supplier_company_payment_methods.fields.company')),

                                TextEntry::make('payment_method')
                                    ->label(__('supplier_company_payment_methods.fields.payment_method'))
                                    ->badge(),
                            ])
                            ->columns(2),
                    ])
                    ->collapsible(),

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
