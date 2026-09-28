<?php

declare(strict_types=1);

namespace App\Filament\Resources\AnalyticalReports\Pages;

use App\Filament\Resources\AnalyticalReports\Actions\DownloadAnalyticalReportAction;
use App\Filament\Resources\AnalyticalReports\Actions\RetryAnalyticalReportAction;
use App\Filament\Resources\AnalyticalReports\AnalyticalReportResource;
use App\Models\AnalyticalReport;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Livewire\Partials\PartialsComponentHook;
use Illuminate\Contracts\Support\Htmlable;

final class ViewAnalyticalReport extends ViewRecord
{
    protected static string $resource = AnalyticalReportResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var AnalyticalReport $record */
        $record = $this->getRecord();

        if (filled($record->filename)) {
            return (string) $record->filename;
        }

        return sprintf(
            '%s %s – %s',
            __('analytical_reports.label'),
            $record->period_start->format('d/m/Y'),
            $record->period_end->format('d/m/Y'),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            DownloadAnalyticalReportAction::make(),
            RetryAnalyticalReportAction::make(),
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make()
                ->requiresConfirmation(),
        ];
    }

    /**
     * Schema `poll()` only refreshes its own partial, so the download action would stay hidden.
     * Forcing a full render lets the header follow the status.
     */
    public function refreshReport(): void
    {
        app(PartialsComponentHook::class)->forceRender($this);
    }
}
