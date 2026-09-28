<?php

declare(strict_types=1);

namespace App\Filament\Resources\AnalyticalReports\Actions;

use App\Actions\Report\DownloadAnalyticalReportAction as DownloadAnalyticalReport;
use App\Exceptions\BusinessException;
use App\Models\AnalyticalReport;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadAnalyticalReportAction
{
    public static function make(): Action
    {
        return Action::make('downloadAnalyticalReport')
            ->label(__('analytical_reports.actions.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('success')
            ->visible(fn (?AnalyticalReport $record): bool => $record !== null
                && (Filament::auth()->user()?->can('download', $record) ?? false))
            ->action(function (AnalyticalReport $record, Action $action): ?StreamedResponse {
                $user = Filament::auth()->user();

                abort_unless($user?->can('download', $record) ?? false, 403);

                try {
                    return app(DownloadAnalyticalReport::class)($record, $user, request()->ip());
                } catch (BusinessException $exception) {
                    Notification::make()
                        ->title($exception->getUserMessage())
                        ->danger()
                        ->send();

                    $action->halt();

                    return null;
                }
            });
    }
}
