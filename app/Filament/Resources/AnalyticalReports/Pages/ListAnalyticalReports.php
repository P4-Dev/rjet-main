<?php

declare(strict_types=1);

namespace App\Filament\Resources\AnalyticalReports\Pages;

use App\Filament\Resources\AnalyticalReports\Actions\RequestAnalyticalReportAction;
use App\Filament\Resources\AnalyticalReports\AnalyticalReportResource;
use Filament\Resources\Pages\ListRecords;

final class ListAnalyticalReports extends ListRecords
{
    protected static string $resource = AnalyticalReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RequestAnalyticalReportAction::make(),
        ];
    }
}
