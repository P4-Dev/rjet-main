<?php

declare(strict_types=1);

namespace App\Filament\Resources\Banks\Actions;

use App\Exceptions\BankException;
use App\Models\Bank;
use App\Services\BankService;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;

final class DeleteBankAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->before(function (Bank $record, DeleteAction $action): void {
                try {
                    app(BankService::class)->ensureDeletable($record);
                } catch (BankException $exception) {
                    Notification::make()
                        ->title($exception->getUserMessage())
                        ->danger()
                        ->send();

                    $action->halt();
                }
            });
    }
}
