<?php

declare(strict_types=1);

namespace App\Filament\Resources\Branches\Actions;

use App\Exceptions\BranchException;
use App\Models\Branch;
use App\Services\BranchService;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;

/**
 * Exclusão de filial com o guard cannotDeleteWithBankAccounts() aplicado via
 * BranchService (nunca como evento Eloquent no Model). O `before()` apenas
 * valida; a exclusão/redirect/notificação de sucesso seguem o fluxo padrão da
 * DeleteAction quando o guard não bloqueia.
 */
final class DeleteBranchAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->before(function (Branch $record, DeleteAction $action): void {
                try {
                    app(BranchService::class)->ensureDeletable($record);
                } catch (BranchException $exception) {
                    Notification::make()
                        ->title($exception->getUserMessage())
                        ->danger()
                        ->send();

                    $action->halt();
                }
            });
    }
}
