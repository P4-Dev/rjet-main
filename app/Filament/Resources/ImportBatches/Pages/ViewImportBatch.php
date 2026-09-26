<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportBatches\Pages;

use App\Filament\Resources\ImportBatches\Actions\DownloadImportSpreadsheetAction;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;

final class ViewImportBatch extends ViewRecord
{
    protected static string $resource = ImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DownloadImportSpreadsheetAction::make(),
            DeleteAction::make()
                ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false),
        ];
    }

    public function getPollingInterval(): ?string
    {
        if ($this->record?->status?->isTerminal()) {
            return null;
        }

        return '5s';
    }
}
