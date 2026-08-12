<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApprovalRules\Tables;

use App\Exceptions\BusinessException;
use App\Services\ApprovalRuleService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

final class ApprovalRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('branch.company.name')
                    ->label(__('approval_rules.fields.company'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('branch.name')
                    ->label(__('approval_rules.fields.branch_id'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('min_amount')
                    ->label(__('approval_rules.fields.min_amount'))
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('max_amount')
                    ->label(__('approval_rules.fields.max_amount'))
                    ->money('BRL')
                    ->sortable()
                    ->placeholder('∞'),
                TextColumn::make('approver.name')
                    ->label(__('approval_rules.fields.approver_user_id'))
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label(__('common.fields.is_active'))
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label(__('common.fields.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('branch_id')
                    ->label(__('approval_rules.fields.branch_id'))
                    ->relationship('branch', 'name')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('is_active')
                    ->label(__('common.fields.is_active')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->using(function ($record): void {
                        try {
                            app(ApprovalRuleService::class)->delete($record);
                        } catch (BusinessException $exception) {
                            Notification::make()
                                ->title($exception->getUserMessage())
                                ->danger()
                                ->send();

                            throw $exception;
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
