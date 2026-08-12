<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApprovalRules\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ApprovalRuleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('approval_rules.sections.rule'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('branch.company.name')
                            ->label(__('approval_rules.fields.company')),
                        TextEntry::make('branch.name')
                            ->label(__('approval_rules.fields.branch_id')),
                        TextEntry::make('min_amount')
                            ->label(__('approval_rules.fields.min_amount'))
                            ->money('BRL'),
                        TextEntry::make('max_amount')
                            ->label(__('approval_rules.fields.max_amount'))
                            ->money('BRL')
                            ->placeholder('∞'),
                        TextEntry::make('approver.name')
                            ->label(__('approval_rules.fields.approver_user_id')),
                        IconEntry::make('is_active')
                            ->label(__('common.fields.is_active'))
                            ->boolean(),
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
