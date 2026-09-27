<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSettlements\Actions;

use App\Actions\Cnab\DownloadCnabFileAction as DownloadCnabFile;
use App\Exceptions\BusinessException;
use App\Models\CnabFile;
use App\Models\PaymentSettlement;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * On the settlement view it targets the current file; on the files relation manager, the row.
 */
final class DownloadCnabFileAction
{
    public static function make(): Action
    {
        return Action::make('downloadCnab')
            ->label(__('cnab_files.actions.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('success')
            ->visible(function (?Model $record): bool {
                $file = self::resolveFile($record);

                return $file !== null
                    && $file->isDownloadable()
                    && (Filament::auth()->user()?->can('download', $file) ?? false);
            })
            ->action(function (?Model $record, Action $action): ?StreamedResponse {
                $user = Filament::auth()->user();
                $file = self::resolveFile($record);

                abort_unless($file !== null && ($user?->can('download', $file) ?? false), 403);

                try {
                    return app(DownloadCnabFile::class)($file, $user, request()->ip());
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

    private static function resolveFile(?Model $record): ?CnabFile
    {
        return match (true) {
            $record instanceof CnabFile => $record,
            $record instanceof PaymentSettlement => $record->currentCnabFile,
            default => null,
        };
    }
}
