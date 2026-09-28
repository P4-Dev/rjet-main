<?php

declare(strict_types=1);

namespace App\Filament\Resources\AnalyticalReports\Actions;

use App\Actions\Report\RetryAnalyticalReportAction as RetryAnalyticalReport;
use App\Exceptions\BusinessException;
use App\Models\AnalyticalReport;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class RetryAnalyticalReportAction
{
    public static function make(): Action
    {
        return Action::make('retryAnalyticalReport')
            ->label(__('analytical_reports.actions.retry'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(fn (?AnalyticalReport $record): bool => $record !== null
                && (Filament::auth()->user()?->can('retry', $record) ?? false))
            ->requiresConfirmation()
            ->modalHeading(__('analytical_reports.actions.retry'))
            ->action(function (AnalyticalReport $record, Action $action): void {
                $user = Filament::auth()->user();

                abort_unless($user?->can('retry', $record) ?? false, 403);

                try {
                    app(RetryAnalyticalReport::class)($record, $user);
                } catch (BusinessException $exception) {
                    Notification::make()
                        ->title($exception->getUserMessage())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__('analytical_reports.messages.retry_queued'))
                    ->success()
                    ->send();
            });
    }
}
