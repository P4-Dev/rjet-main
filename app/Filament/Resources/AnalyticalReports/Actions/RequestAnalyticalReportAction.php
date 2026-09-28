<?php

declare(strict_types=1);

namespace App\Filament\Resources\AnalyticalReports\Actions;

use App\Actions\Report\RequestAnalyticalReportAction as RequestAnalyticalReport;
use App\DTOs\AnalyticalReportData;
use App\Exceptions\BusinessException;
use App\Filament\Resources\AnalyticalReports\AnalyticalReportResource;
use App\Filament\Resources\AnalyticalReports\Schemas\AnalyticalReportForm;
use App\Models\AnalyticalReport;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class RequestAnalyticalReportAction
{
    public static function make(): Action
    {
        return Action::make('requestAnalyticalReport')
            ->label(__('analytical_reports.actions.request'))
            ->icon(Heroicon::OutlinedDocumentChartBar)
            ->color('primary')
            ->visible(fn (): bool => Filament::auth()->user()?->can('create', AnalyticalReport::class) ?? false)
            ->modalHeading(__('analytical_reports.actions.request'))
            ->schema(AnalyticalReportForm::components())
            ->action(function (array $data, Action $action): void {
                $user = Filament::auth()->user();

                abort_unless($user?->can('create', AnalyticalReport::class) ?? false, 403);

                try {
                    $report = app(RequestAnalyticalReport::class)(AnalyticalReportData::fromArray($data), $user);
                } catch (BusinessException $exception) {
                    Notification::make()
                        ->title($exception->getUserMessage())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__('analytical_reports.messages.queued'))
                    ->success()
                    ->actions([
                        Action::make('view')
                            ->label(__('analytical_reports.actions.view'))
                            ->url(AnalyticalReportResource::getUrl('view', ['record' => $report])),
                    ])
                    ->send();
            });
    }
}
