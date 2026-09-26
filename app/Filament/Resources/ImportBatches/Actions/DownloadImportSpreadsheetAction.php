<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportBatches\Actions;

use App\Models\ImportBatch;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class DownloadImportSpreadsheetAction
{
    public static function make(): Action
    {
        return Action::make('downloadSpreadsheet')
            ->label(__('import_batches.actions.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (?ImportBatch $record): bool => $record !== null
                && (Filament::auth()->user()?->can('view', $record) ?? false)
                && filled($record->path)
                && Storage::disk($record->disk)->exists($record->path))
            ->action(function (ImportBatch $record, Action $action): mixed {
                abort_unless(
                    Filament::auth()->user()?->can('view', $record) ?? false,
                    403,
                );

                try {
                    return Storage::disk($record->disk)->download($record->path, $record->original_filename);
                } catch (Throwable) {
                    Notification::make()
                        ->title(__('import_batches.errors.unreadable'))
                        ->danger()
                        ->send();

                    $action->halt();

                    return null;
                }
            });
    }
}
