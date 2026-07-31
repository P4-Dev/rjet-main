<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\Actions;

use App\Models\Attachment;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class DownloadAttachmentAction
{
    public static function make(): Action
    {
        return Action::make('download')
            ->label(__('attachments.actions.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(function (Attachment $record, Action $action): mixed {
                abort_unless(
                    Filament::auth()->user()?->can('view', $record) ?? false,
                    403,
                );

                $temporaryUrl = $record->temporaryUrl();

                if ($temporaryUrl !== null) {
                    return redirect()->away($temporaryUrl);
                }

                try {
                    return Storage::disk($record->disk)->download($record->path, $record->original_name);
                } catch (Throwable) {
                    Notification::make()
                        ->title(__('attachments.errors.file_not_found'))
                        ->danger()
                        ->send();

                    $action->halt();

                    return null;
                }
            });
    }
}
