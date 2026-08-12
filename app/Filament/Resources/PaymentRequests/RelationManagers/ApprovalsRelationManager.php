<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\RelationManagers;

use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ApprovalsRelationManager extends RelationManager
{
    protected static string $relationship = 'approvals';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('approvals.plural');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('status')->badge(),
            TextEntry::make('approver.name')->label(__('approvals.fields.approver')),
            TextEntry::make('reason')->label(__('approvals.fields.reason'))->placeholder('—'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['approver', 'decidedBy', 'reassignments']))
            ->columns([
                TextColumn::make('status')
                    ->label(__('approvals.fields.status'))
                    ->badge(),
                TextColumn::make('approver.name')
                    ->label(__('approvals.fields.approver')),
                TextColumn::make('amount_snapshot')
                    ->label(__('approvals.fields.amount_snapshot'))
                    ->money('BRL'),
                TextColumn::make('assigned_at')
                    ->label(__('approvals.fields.assigned_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('due_at')
                    ->label(__('approvals.fields.due_at'))
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('decided_at')
                    ->label(__('approvals.fields.decided_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                TextColumn::make('decidedBy.name')
                    ->label(__('approvals.fields.decided_by'))
                    ->placeholder('—'),
                TextColumn::make('reason')
                    ->label(__('approvals.fields.reason'))
                    ->limit(40)
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('escalated_at')
                    ->label(__('approvals.fields.escalated_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                TextColumn::make('reassignments_count')
                    ->counts('reassignments')
                    ->label(__('approvals.fields.reassignments')),
                TextColumn::make('material_fingerprint')
                    ->label(__('approvals.fields.material_fingerprint'))
                    ->limit(12)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('assigned_at', 'desc');
    }
}
